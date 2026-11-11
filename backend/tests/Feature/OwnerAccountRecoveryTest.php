<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Tests\TestCase;

class OwnerAccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'owner@example.test'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('OriginalPass!2026'),
        ]);
        Owner::query()->forceCreate([
            'user_id' => $user->id,
            'name' => 'Original Owner',
        ]);

        return $user;
    }

    public function test_owner_can_update_only_own_profile_without_changing_email_or_owner_id(): void
    {
        $user = $this->owner();
        $other = $this->owner('other@example.test');
        $otherOwner = Owner::query()->where('user_id', $other->id)->firstOrFail();

        $this->actingAs($user)->patchJson('/api/v1/account', [
            'name' => 'Updated Owner',
            'phone' => '0501234567',
            'email' => 'attacker@example.test',
            'owner_id' => $otherOwner->id,
        ])->assertOk()->assertJsonPath('data.name', 'Updated Owner')
            ->assertJsonPath('data.phone', '0501234567')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.owner_id');

        $this->assertDatabaseHas('owners', ['user_id' => $user->id, 'name' => 'Updated Owner']);
        $this->assertDatabaseHas('owners', ['id' => $otherOwner->id, 'name' => 'Original Owner']);
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.test']);
        $this->getJson('/api/v1/account')->assertOk()
            ->assertJsonPath('data.name', 'Updated Owner');
    }

    public function test_wrong_current_password_never_updates_account(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->postJson('/api/v1/account/change-password', [
            'current_password' => 'incorrect',
            'password' => 'NewSecurePass!2026',
            'password_confirmation' => 'NewSecurePass!2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('OriginalPass!2026', $user->fresh()->password));
    }

    public function test_changing_password_revokes_authenticated_session_and_old_credentials(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->postJson('/api/v1/account/change-password', [
            'current_password' => 'OriginalPass!2026',
            'password' => 'NewSecurePass!2026',
            'password_confirmation' => 'NewSecurePass!2026',
        ])->assertOk();
        $this->assertGuest();
        $this->assertTrue(Hash::check('NewSecurePass!2026', $user->fresh()->password));
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/login', ['email' => $user->email, 'password' => 'OriginalPass!2026'])
            ->assertUnprocessable();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'NewSecurePass!2026'])
            ->assertOk();
    }

    public function test_recovery_request_is_non_enumerating_and_sends_notification_for_known_user(): void
    {
        Notification::fake();
        $user = $this->owner();
        $known = $this->postJson('/forgot-password', ['email' => $user->email])
            ->assertOk()->json('message');
        $unknown = $this->postJson('/forgot-password', ['email' => 'unknown@example.test'])
            ->assertOk()->json('message');
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unknown@example.test']);
    }

    public function test_reset_link_has_only_fragment_token_and_no_external_domain(): void
    {
        $user = $this->owner();
        $notification = new ResetPassword('example-test-token');
        $mail = $notification->toMail($user);
        $this->assertStringContainsString('/staging/#reset=example-test-token&email=', $mail->actionUrl);
        $this->assertStringNotContainsString('/reset-password?token=', $mail->actionUrl);
    }

    public function test_recovery_token_is_one_time_and_old_password_stops_working(): void
    {
        $user = $this->owner();
        $token = PasswordBroker::broker()->createToken($user);

        $request = [
            'email' => $user->email, 'token' => $token,
            'password' => 'RecoveredPass!2026',
            'password_confirmation' => 'RecoveredPass!2026',
        ];
        $this->postJson('/reset-password', $request)->assertOk();
        $this->assertTrue(Hash::check('RecoveredPass!2026', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/reset-password', $request)
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/login', ['email' => $user->email, 'password' => 'OriginalPass!2026'])
            ->assertUnprocessable();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'RecoveredPass!2026'])
            ->assertOk();
    }

    public function test_invalid_reset_token_does_not_change_password(): void
    {
        $user = $this->owner();
        $this->postJson('/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'NewSecurePass!2026',
            'password_confirmation' => 'NewSecurePass!2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('OriginalPass!2026', $user->fresh()->password));
    }

    public function test_insecure_log_mail_transport_is_blocked_outside_testing(): void
    {
        Notification::fake();
        $this->owner();
        config()->set('mail.default', 'log');

        // Simulate non-test runtime; never send recovery secrets to server logs.
        $this->app['env'] = 'staging';
        try {
            $this->postJson('/forgot-password', ['email' => 'owner@example.test'])
                ->assertStatus(503);
            Notification::assertNothingSent();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_guests_cannot_view_or_mutate_an_owner_account(): void
    {
        $this->getJson('/api/v1/account')->assertUnauthorized();
        $this->patchJson('/api/v1/account', ['name' => 'Hacker'])->assertUnauthorized();
        $this->postJson('/api/v1/account/change-password', [])->assertUnauthorized();
    }
}
