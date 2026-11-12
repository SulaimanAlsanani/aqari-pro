<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'owner@example.test'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('StrongPassword!2026'),
        ]);
        Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Test Owner']);

        return $user;
    }

    private function login(User $user): void
    {
        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'StrongPassword!2026',
        ])->assertOk();
    }

    private function otherSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '192.0.2.25',
            'user_agent' => 'Other Device Browser',
            'payload' => base64_encode('test-only'),
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_login_failures_success_and_logout_are_logged_without_credentials(): void
    {
        $user = $this->owner();

        $this->withHeader('User-Agent', 'Aqari Synthetic Browser')->postJson('/login', [
            'email' => $user->email,
            'password' => 'InvalidSecretNeverToLog',
        ])->assertUnprocessable();

        $this->login($user);
        $this->postJson('/logout')->assertOk();

        $events = DB::table('owner_login_events')
            ->where('user_id', $user->id)->pluck('event')->all();
        $this->assertCount(3, $events);
        $this->assertContains('login_failed', $events);
        $this->assertContains('login_succeeded', $events);
        $this->assertContains('logout', $events);
        $raw = json_encode(DB::table('owner_login_events')->get()->toArray());
        $this->assertStringNotContainsString('InvalidSecretNeverToLog', $raw);
        $this->assertStringNotContainsString('StrongPassword!2026', $raw);
        $this->assertStringNotContainsString($user->email, $raw);
    }

    public function test_owner_session_list_is_scoped_and_never_exposes_session_ids(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->owner();
        $other = $this->owner('other@example.test');

        $this->login($user);
        $this->otherSession($user->id, 'other-owner-owned-session-id');
        $this->otherSession($other->id, 'different-owner-session-id');

        $response = $this->getJson('/account/sessions')->assertOk();
        $response->assertJsonFragment(['device' => 'Other Device Browser']);
        $response->assertJsonPath('recent_activity.0.event', 'login_succeeded');

        $body = $response->getContent();
        $this->assertStringNotContainsString('other-owner-owned-session-id', $body);
        $this->assertStringNotContainsString('different-owner-session-id', $body);
        $this->assertStringNotContainsString('payload', $body);
        $this->assertStringNotContainsString($other->email, $body);
        $this->assertSame(2, collect($response->json('data'))->count());
    }

    public function test_revoke_other_sessions_requires_correct_password_and_keeps_this_device(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->owner();
        $other = $this->owner('other@example.test');
        $this->login($user);
        $this->otherSession($user->id, 'remote-owner-session');
        $this->otherSession($other->id, 'unrelated-owner-session');

        $this->postJson('/account/sessions/revoke-others', [
            'current_password' => 'not-correct',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseHas('sessions', ['id' => 'remote-owner-session']);

        $this->postJson('/account/sessions/revoke-others', [
            'current_password' => 'StrongPassword!2026',
        ])->assertOk()->assertJsonPath('revoked', 1);
        $this->assertDatabaseMissing('sessions', ['id' => 'remote-owner-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-owner-session']);
        $this->assertDatabaseHas('owner_login_events', [
            'user_id' => $user->id,
            'event' => 'other_sessions_revoked',
        ]);
        $this->getJson('/account/sessions')->assertOk();
    }

    public function test_guests_and_accounts_without_owner_record_are_denied(): void
    {
        $this->getJson('/account/sessions')->assertUnauthorized();
        $this->postJson('/account/sessions/revoke-others', [
            'current_password' => 'StrongPassword!2026',
        ])->assertUnauthorized();

        $nonOwner = User::factory()->create([
            'email' => 'not-an-owner@example.test',
            'password' => Hash::make('StrongPassword!2026'),
        ]);
        $this->login($nonOwner);
        $this->getJson('/account/sessions')->assertForbidden();
        $this->postJson('/account/sessions/revoke-others', [
            'current_password' => 'StrongPassword!2026',
        ])->assertForbidden();
    }

    public function test_session_management_fails_closed_when_using_non_database_sessions(): void
    {
        config()->set('session.driver', 'array');
        $this->login($this->owner());
        $this->getJson('/account/sessions')->assertStatus(503);
        $this->postJson('/account/sessions/revoke-others', [
            'current_password' => 'StrongPassword!2026',
        ])->assertStatus(503);
    }
}
