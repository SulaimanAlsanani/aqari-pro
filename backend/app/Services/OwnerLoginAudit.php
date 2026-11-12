<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OwnerLoginAudit
{
    /**
     * Security events contain only a known user ID, the request origin and
     * truncated client agent. Never save credentials, email inputs or cookies.
     */
    public function record(Request $request, int $userId, string $event): void
    {
        if (! in_array($event, [
            'login_succeeded', 'login_failed', 'logout', 'other_sessions_revoked',
        ], true)) {
            throw new \InvalidArgumentException('Unknown account security event.');
        }

        DB::table('owner_login_events')->insert([
            'user_id' => $userId,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
