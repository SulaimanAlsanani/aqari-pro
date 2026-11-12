<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->getKey();
        $tokens = $user->tokens()
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']);

        return response()->json([
            'data' => $tokens->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'current' => (string) $token->id === (string) $currentId,
            ]),
            'current_token_id' => $currentId,
        ]);
    }

    public function revoke(Request $request, string $token)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (!Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور غير صحيحة']);
        }

        // Never use an unscoped token lookup: other owners' tokens must remain inaccessible.
        $target = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $current = (string) $request->user()->currentAccessToken()?->getKey() === (string) $target->getKey();
        $target->delete();

        return response()->json([
            'message' => $current ? 'تم إنهاء الجلسة الحالية' : 'تم إنهاء الجلسة المحددة',
            'current_revoked' => $current,
        ]);
    }

    public function revokeOthers(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (!Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'كلمة المرور غير صحيحة']);
        }
        $currentId = $request->user()->currentAccessToken()?->getKey();
        if (!$currentId) {
            return response()->json(['message' => 'يلزم تسجيل الدخول باستخدام رمز وصول'], 403);
        }
        $count = $request->user()->tokens()->where('id', '<>', $currentId)->delete();

        return response()->json([
            'message' => 'تم إنهاء جميع الجلسات الأخرى',
            'revoked_count' => $count,
        ]);
    }
}
