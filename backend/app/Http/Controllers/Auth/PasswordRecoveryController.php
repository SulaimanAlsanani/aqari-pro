<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PasswordRecoveryController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
        ]);

        // Never expose reset tokens in application logs or a public API.
        // A real SMTP transport must be configured before enabling recovery.
        if (! app()->environment('testing') &&
            in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new ServiceUnavailableHttpException(null, 'خدمة استعادة كلمة المرور غير مهيأة بعد.');
        }

        PasswordBroker::broker()->sendResetLink([
            'email' => mb_strtolower(trim($data['email'])),
        ]);

        // The response must not distinguish an unknown account from a known one.
        return response()->json([
            'message' => 'إذا كان البريد مسجلًا، فستصلك تعليمات استعادة كلمة المرور.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254'],
            'password' => [
                'required', 'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required', 'string'],
        ]);

        $status = PasswordBroker::broker()->reset([
            'token' => $data['token'],
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
        ], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => null,
                ])->save();

                // Session replay must not survive resetting a password.
                if (config('session.driver') === 'database') {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }
            });
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['رابط الاستعادة غير صالح أو منتهي الصلاحية. اطلب رابطًا جديدًا.'],
            ]);
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'تم تعيين كلمة المرور الجديدة. سجّل الدخول مجددًا.']);
    }
}
