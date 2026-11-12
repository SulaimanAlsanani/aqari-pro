<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OwnerLoginAudit;
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

        if (! Auth::guard('web')->attempt($credentials)) {
            $knownUserId = User::query()->where('email', $credentials['email'])->value('id');
            if ($knownUserId !== null) {
                $audit->record($request, (int) $knownUserId, 'login_failed');
            }
            throw ValidationException::withMessages([
                'email' => ['بيانات تسجيل الدخول غير صحيحة.'],
            ]);
        }

        // Rotate session identifier to prevent session fixation.
        $request->session()->regenerate();
        $audit->record($request, (int) Auth::guard('web')->id(), 'login_succeeded');

        return response()->json([
            'user' => Auth::guard('web')->user()->only(['id', 'name', 'email']),
        ]);
    }

    public function logout(Request $request, OwnerLoginAudit $audit): JsonResponse
    {
        $userId = Auth::guard('web')->id();
        if ($userId !== null) {
            $audit->record($request, (int) $userId, 'logout');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }
}
