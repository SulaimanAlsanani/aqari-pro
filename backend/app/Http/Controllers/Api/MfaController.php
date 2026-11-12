<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public function status(Request $request) {
        return response()->json(['enabled' => (bool) $request->user()->mfa_enabled_at]);
    }

    public function setup(Request $request) {
        $data = $request->validate(['password'=>['required','string']]);
        $user = $request->user();
        if (!Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password'=>'كلمة المرور غير صحيحة']);
        }
        if ($user->mfa_enabled_at) {
            return response()->json(['message'=>'المصادقة الثنائية مفعّلة بالفعل'],409);
        }
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_pending_secret'=>$secret])->save();
        $label = rawurlencode('Aqari Pro:'.$user->email);
        $uri = 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode('Aqari Pro').'&algorithm=SHA1&digits=6&period=30';
        return response()->json(['secret'=>$secret,'otpauth_url'=>$uri,'message'=>'احفظ المفتاح في تطبيق المصادقة ثم أكّد برمز صالح']);
    }

    public function confirm(Request $request) {
        $data = $request->validate(['code'=>['required','regex:/^[0-9]{6}$/D']]);
        return DB::transaction(function () use ($request,$data) {
            $user=User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if ($user->mfa_enabled_at || !$user->mfa_pending_secret) {
                return response()->json(['message'=>'لا يوجد إعداد قيد التفعيل'],409);
            }
            if (!Totp::verify($user->mfa_pending_secret,$data['code'],time())) {
                throw ValidationException::withMessages(['code'=>'رمز التحقق غير صحيح']);
            }
            $recovery=[];
            for ($i=0;$i<8;$i++) $recovery[]=strtoupper(bin2hex(random_bytes(8)));
            $user->forceFill([
                'mfa_secret'=>$user->mfa_pending_secret,
                'mfa_pending_secret'=>null,
                'mfa_enabled_at'=>now(),
                'mfa_last_step'=>intdiv(time(),30),
                'mfa_recovery_codes'=>array_map(fn($v)=>hash('sha256',$v),$recovery),
            ])->save();
            // Existing sessions predate MFA verification. Revoke all of them.
            $user->tokens()->delete();
            return response()->json(['enabled'=>true,'recovery_codes'=>$recovery,
                'message'=>'تم التفعيل وإلغاء الجلسات السابقة. احفظ رموز الاسترداد الآن؛ لن تُعرض مجددًا']);
        });
    }

    public function disable(Request $request) {
        $data=$request->validate(['password'=>['required','string'],'code'=>['required','string']]);
        return DB::transaction(function () use ($request,$data) {
            $user=User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (!Hash::check($data['password'],$user->password) || !$user->mfa_enabled_at ||
                !self::consumeCode($user,$data['code'])) {
                throw ValidationException::withMessages(['code'=>'تعذر التحقق من بيانات الأمان']);
            }
            $user->forceFill(['mfa_secret'=>null,'mfa_pending_secret'=>null,
                'mfa_recovery_codes'=>null,'mfa_enabled_at'=>null,'mfa_last_step'=>null])->save();
            $user->tokens()->delete();
            return response()->json(['enabled'=>false,'message'=>'تم التعطيل وإلغاء جميع الجلسات']);
        });
    }

    // Call only while holding a row lock. Recovery hashes are one-time credentials.
    public static function consumeCode(User $user,string $code): bool {
        $normalized=strtoupper(str_replace('-','',trim($code)));
        if (preg_match('/^[A-F0-9]{16}$/D',$normalized)) {
            $hash=hash('sha256',$normalized);
            $codes=$user->mfa_recovery_codes ?? [];
            $index=array_search($hash,$codes,true);
            if ($index===false) return false;
            array_splice($codes,$index,1);
            $user->forceFill(['mfa_recovery_codes'=>$codes])->save();
            return true;
        }
        if (!preg_match('/^[0-9]{6}$/D',$code)) return false;
        $step=intdiv(time(),30);
        foreach ([-1,0,1] as $drift) {
            $candidate=$step+$drift;
            if ($candidate<0 || $candidate<=($user->mfa_last_step ?? -1)) continue;
            if (hash_equals(Totp::code($user->mfa_secret,$candidate*30),$code)) {
                $user->forceFill(['mfa_last_step'=>$candidate])->save();
                return true;
            }
        }
        return false;
    }
}
