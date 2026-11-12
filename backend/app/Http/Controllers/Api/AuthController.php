<?php
namespace App\Http\Controllers\Api;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
 public function login(Request $request){
  $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);
  $user=User::where('email',$credentials['email'])->first();
  if(!$user||!Hash::check($credentials['password'],$user->password))
   return response()->json(['message'=>'بيانات الدخول غير صحيحة'],422);
  if($user->mfa_enabled_at){
   if(!$request->filled('mfa_code'))
    return response()->json(['message'=>'رمز المصادقة الثنائية مطلوب','mfa_required'=>true],428);
   $data=$request->validate(['mfa_code'=>['required','string','max:32']]);
   $passed=DB::transaction(function()use($user,$data){
    $locked=User::query()->lockForUpdate()->findOrFail($user->id);
    return \App\Http\Controllers\Api\MfaController::consumeCode($locked,$data['mfa_code']);
   });
   if(!$passed) throw ValidationException::withMessages(['mfa_code'=>'رمز المصادقة غير صحيح']);
  }
  $token=$user->createToken('aqari-web', ['*'], now()->addHours(12))->plainTextToken;
  return response()->json(['token'=>$token,'token_type'=>'Bearer','expires_in'=>43200,'user'=>['id'=>$user->id,'name'=>$user->name]]);
 }
 public function me(Request $request){return response()->json(['id'=>$request->user()->id,'name'=>$request->user()->name,'email'=>$request->user()->email,'mfa_enabled'=>(bool)$request->user()->mfa_enabled_at]);}
 public function changePassword(Request $request){
  $input=$request->validate([
   'current_password'=>['required','string'],
   'password'=>['required','string','min:12','confirmed','different:current_password']
  ]);
  $user=$request->user();
  if(!Hash::check($input['current_password'],$user->password))
   throw ValidationException::withMessages(['current_password'=>'كلمة المرور الحالية غير صحيحة']);
  $user->forceFill(['password'=>Hash::make($input['password'])])->save();
  $user->tokens()->delete();
  return response()->json(['message'=>'تم تغيير كلمة المرور وإلغاء جميع الجلسات؛ سجل الدخول مجددًا']);
 }
 public function logout(Request $request){$request->user()->currentAccessToken()?->delete();return response()->json(['message'=>'تم تسجيل الخروج']);}
}
