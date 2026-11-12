<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Services\OwnerLoginAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class SessionSecurityController extends Controller
{
    private function ownerUserId(Request $request): int
    {
        $userId = (int) Auth::guard('web')->id();

        abort_unless($userId > 0 && Owner::query()
            ->where('user_id', $userId)->exists(), 403);

        return $userId;
    }

    private function requireDatabaseSessions(Request $request): void
    {
        if (! $request->hasSession() || config('session.driver') !== 'database') {
            throw new ServiceUnavailableHttpException(
                null, 'إدارة الأجهزة تتطلب جلسات مخزنة في قاعدة البيانات.'
            );
        }
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $this->ownerUserId($request);
        $this->requireDatabaseSessions($request);
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')->where('user_id', $userId)
            ->orderByDesc('last_activity')->limit(25)->get([
                'id', 'ip_address', 'user_agent', 'last_activity',
            ])->map(fn ($session): array => [
                'current' => hash_equals($currentId, (string) $session->id),
                'ip_address' => $session->ip_address,
                'device' => mb_substr((string) $session->user_agent, 0, 255),
                'last_activity' => (int) $session->last_activity,
            ]);

        $events = DB::table('owner_login_events')->where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(15)
            ->get(['event', 'ip_address', 'user_agent', 'created_at'])
            ->map(fn ($event): array => [
                'event' => $event->event,
                'ip_address' => $event->ip_address,
                'device' => mb_substr((string) $event->user_agent, 0, 255),
                'occurred_at' => $event->created_at,
            ]);

        return response()->json([
            'data' => $sessions->values(),
            'recent_activity' => $events->values(),
        ]);
    }

    public function revokeOthers(
        Request $request,
        OwnerLoginAudit $audit
    ): JsonResponse {
        $userId = $this->ownerUserId($request);
        $this->requireDatabaseSessions($request);

        $request->validate([
            'current_password' => ['required', 'current_password:web'],
        ]);

        $sessionId = $request->session()->getId();
        $removed = DB::table('sessions')->where('user_id', $userId)
            ->where('id', '!=', $sessionId)->delete();

        $audit->record($request, $userId, 'other_sessions_revoked');

        return response()->json([
            'message' => 'تم تسجيل خروج الأجهزة الأخرى.',
            'revoked' => $removed,
        ]);
    }
}
