<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OwnerLoginRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function insertEvent(int $userId, string $event, int $daysAgo): void
    {
        DB::table('owner_login_events')->insert([
            'user_id' => $userId,
            'event' => $event,
            'ip_address' => '192.0.2.25',
            'user_agent' => 'Synthetic Unit Test',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_dry_run_does_not_delete_any_security_events(): void
    {
        $user = User::factory()->create();
        $this->insertEvent($user->id, 'login_succeeded', 120);

        $this->artisan('aqari:prune-login-events')->assertExitCode(0);
        $this->assertDatabaseCount('owner_login_events', 1);
    }

    public function test_explicit_execute_only_removes_events_older_than_retention(): void
    {
        $user = User::factory()->create();
        $this->insertEvent($user->id, 'login_failed', 121);
        $this->insertEvent($user->id, 'login_succeeded', 90);
        $this->insertEvent($user->id, 'logout', 2);

        $this->artisan('aqari:prune-login-events', [
            '--days' => 100,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('owner_login_events', 2);
        $this->assertDatabaseHas('owner_login_events', [
            'user_id' => $user->id, 'event' => 'login_succeeded',
        ]);
        $this->assertDatabaseHas('owner_login_events', [
            'user_id' => $user->id, 'event' => 'logout',
        ]);
        $this->assertDatabaseMissing('owner_login_events', [
            'user_id' => $user->id, 'event' => 'login_failed',
        ]);
    }

    public function test_invalid_or_excessively_short_retention_cannot_delete_records(): void
    {
        $user = User::factory()->create();
        $this->insertEvent($user->id, 'login_succeeded', 121);

        foreach (['0', '29', '366', '-1', 'bad'] as $days) {
            $this->artisan('aqari:prune-login-events', [
                '--days' => $days, '--execute' => true,
            ])->assertExitCode(1);
        }

        $this->assertDatabaseCount('owner_login_events', 1);
    }
}
