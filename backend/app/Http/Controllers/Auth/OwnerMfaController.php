<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Services\OwnerLoginAudit;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class OwnerMfaController extends Controller
{
    private function owner(Request $request)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user && Owner::query()->where('user_id', $user->id)->exists(), 403);

        return $user;
    }

    public function status(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        return response()->json(['data' => [
            'enabled' => $user->mfa_enabled_at !== null && $user->mfa_secret !== null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function start(Request $request, TotpService $totp): JsonResponse
    {
        $user = $this->owner($request);
        $request->validate(['current_password' => ['required', 'current_password:web']]);
        if ($user->mfa_enabled_at !== null) {
            return response()->json(['message' => 'المصادقة الثنائية مفعّلة بالفعل.'], 409);
        }
        if (config('session.driver') !== 'database') {
            throw new ServiceUnavailableHttpException(
                null, 'تفعيل المصادقة الثنائية يحتاج قاعدة جلسات تدعم إلغاء الأجهزة الأخرى.'
            );
        }

        $secret = $totp->newSecret();
        $user->forceFill([
            'mfa_pending_secret' => Crypt::encryptString($secret),
            'mfa_pending_at' => now(),
        ])->save();

        return response()->json(['data' => [
            'secret' => $secret,
            'provisioning_uri' => $totp->provisioningUri($secret, $user->email),
            'expires_in_seconds' => 600,
        ]])->header('Cache-Control', 'no-store');
    }

    public function confirm(
        Request $request,
        TotpService $totp,
        OwnerLoginAudit $audit
    ): JsonResponse {
        $user = $this->owner($request);
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        if ($user->mfa_enabled_at || ! $user->mfa_pending_secret ||
            ! $user->mfa_pending_at ||
            now()->greaterThan(\Illuminate\Support\Carbon::parse($user->mfa_pending_at)->addMinutes(10))) {
            throw ValidationException::withMessages([
                'code' => ['طلب الإعداد منتهي الصلاحية. ابدأ الإعداد من جديد.'],
            ]);
        }

        $secret = Crypt::decryptString($user->mfa_pending_secret);
        $step = $totp->matchingStep($secret, $data['code'], now()->timestamp);
        if ($step === null) {
            throw ValidationException::withMessages([
                'code' => ['رمز المصادقة غير صالح.'],
            ]);
        }

        $changed = DB::table('users')->where('id', $user->id)
            ->whereNull('mfa_enabled_at')
            ->where('mfa_pending_secret', $user->mfa_pending_secret)
            ->update([
                'mfa_secret' => Crypt::encryptString($secret),
                'mfa_pending_secret' => null,
                'mfa_pending_at' => null,
                'mfa_enabled_at' => now(),
                'mfa_last_step' => $step,
            ]);
        if ($changed !== 1) {
            throw ValidationException::withMessages([
                'code' => ['تغيّر إعداد المصادقة الثنائية. حاول مرة أخرى.'],
            ]);
        }

        // Existing sessions and API tokens must not bypass a newly enabled MFA
        // requirement. Keep only this current browser session.
        DB::table('sessions')->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())->delete();
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
        $audit->record($request, (int) $user->id, 'mfa_enabled');

        return response()->json(['message' => 'تم تفعيل المصادقة الثنائية بنجاح.'])
            ->header('Cache-Control', 'no-store');
    }

    public function disable(
        Request $request,
        TotpService $totp,
        OwnerLoginAudit $audit
    ): JsonResponse {
        $user = $this->owner($request);
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'code' => ['required', 'regex:/^[0-9]{6}$/D'],
        ]);
        if (! $user->mfa_enabled_at || ! $totp->consume($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['رمز المصادقة غير صالح.']]);
        }

        $user->forceFill([
            'mfa_secret' => null,
            'mfa_pending_secret' => null,
            'mfa_pending_at' => null,
            'mfa_enabled_at' => null,
            'mfa_last_step' => null,
        ])->save();
        $audit->record($request, (int) $user->id, 'mfa_disabled');

        return response()->json(['message' => 'تم إيقاف المصادقة الثنائية.'])
            ->header('Cache-Control', 'no-store');
    }
}
