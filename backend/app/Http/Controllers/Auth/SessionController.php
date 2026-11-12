<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OwnerLoginAudit;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function login(Request $request, OwnerLoginAudit $audit): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string'],
        ]);

        // Validate the first factor WITHOUT creating an authenticated browser
        // session. Otherwise an MFA-protected owner could access API routes
        // before presenting the second factor.
        if (! Auth::guard('web')->validate($credentials)) {
            $knownUserId = User::query()->where('email', $credentials['email'])->value('id');
            if ($knownUserId !== null) {
                $audit->record($request, (int) $knownUserId, 'login_failed');
            }
            throw ValidationException::withMessages([
                'email' => ['بيانات تسجيل الدخول غير صحيحة.'],
            ]);
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();
        if ($user->mfa_enabled_at !== null) {
            // An inconsistent MFA record must NEVER downgrade to password-only.
            if (! $user->mfa_secret) {
                throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(
                    null, 'المصادقة الثنائية لهذا الحساب تحتاج إصلاحًا بواسطة مشغّل النظام.'
                );
            }
            // A request to start another account's login must not retain the
            // previous account's authentication or its existing session ID.
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }
            if (method_exists(Auth::guard('sanctum'), 'forgetUser')) {
                Auth::guard('sanctum')->forgetUser();
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->put([
                'mfa_pending_user_id' => (int) $user->id,
                'mfa_pending_until' => now()->addMinutes(5)->timestamp,
                'mfa_pending_attempts' => 0,
            ]);

            return response()->json([
                'mfa_required' => true,
                'message' => 'أدخل رمز المصادقة الثنائية لإكمال تسجيل الدخول.',
            ])->header('Cache-Control', 'no-store');
        }

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات تسجيل الدخول غير صحيحة.'],
            ]);
        }

        $request->session()->forget([
            'mfa_pending_user_id', 'mfa_pending_until', 'mfa_pending_attempts',
        ]);
        $request->session()->regenerate();
        $audit->record($request, (int) Auth::guard('web')->id(), 'login_succeeded');

        return response()->json([
            'user' => Auth::guard('web')->user()->only(['id', 'name', 'email']),
        ]);
    }

    public function verifyMfa(
        Request $request,
        TotpService $totp,
        OwnerLoginAudit $audit
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'regex:/^[0-9]{6}$/D'],
        ]);
        $userId = (int) $request->session()->get('mfa_pending_user_id', 0);
        $until = (int) $request->session()->get('mfa_pending_until', 0);
        $attempts = (int) $request->session()->get('mfa_pending_attempts', 0);

        if (! $userId || $until < now()->timestamp || $attempts >= 5) {
            $request->session()->forget([
                'mfa_pending_user_id', 'mfa_pending_until', 'mfa_pending_attempts',
            ]);
            throw ValidationException::withMessages([
                'code' => ['انتهت جلسة التحقق. أعد تسجيل الدخول.'],
            ]);
        }

        $user = User::query()->find($userId);
        if (! $user || ! $user->mfa_enabled_at || ! $totp->consume($user, $data['code'])) {
            $request->session()->put('mfa_pending_attempts', $attempts + 1);
            if ($user) {
                $audit->record($request, $userId, 'mfa_failed');
            }
            if (method_exists(Auth::guard('sanctum'), 'forgetUser')) {
                Auth::guard('sanctum')->forgetUser();
            }
            throw ValidationException::withMessages([
                'code' => ['رمز المصادقة غير صالح أو مستخدم سابقًا.'],
            ]);
        }

        $request->session()->forget([
            'mfa_pending_user_id', 'mfa_pending_until', 'mfa_pending_attempts',
        ]);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $audit->record($request, (int) $user->id, 'login_succeeded');

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
        ])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, OwnerLoginAudit $audit): JsonResponse
    {
        $userId = Auth::guard('web')->id();
        if ($userId !== null) {
            $audit->record($request, (int) $userId, 'logout');
        }

        Auth::guard('web')->logout();
        if (method_exists(Auth::guard('sanctum'), 'forgetUser')) {
            Auth::guard('sanctum')->forgetUser();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }
}
