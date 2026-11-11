<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class OwnerAccountController extends Controller
{
    private function owner(Request $request): Owner
    {
        return Owner::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    public function show(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        return response()->json(['data' => [
            'name' => $owner->name,
            'phone' => $owner->phone,
            'email' => $request->user()->email,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $owner = $this->owner($request);
        DB::transaction(function () use ($owner, $request, $data): void {
            $owner->update([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
            ]);
            // Keep the account's display name consistent with its one-to-one owner profile.
            $request->user()->forceFill(['name' => $data['name']])->save();
        });

        return $this->show($request);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => [
                'required', 'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);
        $user = $request->user();

        DB::transaction(function () use ($user, $data): void {
            $user->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => null,
            ])->save();
            // Revoke browser sessions issued by this owner. No other owner's
            // sessions are touched. The staging stack uses database sessions.
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
        });

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'تم تغيير كلمة المرور. سجّل الدخول مجددًا.']);
    }
}
