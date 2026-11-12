<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class OwnerMfaTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create([
            'email' => 'secure-owner@example.test',
            'password' => Hash::make('StrongPassword!2026'),
        ]);
        Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'MFA Test Owner']);

        return $user;
    }

    private function enroll(User $user): string
    {
        config()->set('session.driver', 'database');
        $this->travelTo(Carbon::parse('2026-11-12 12:00:15 UTC'));
        $this->actingAs($user);
        $data = $this->postJson('/account/mfa/start', [
            'current_password' => 'StrongPassword!2026',
        ])->assertOk()->json('data');

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $data['secret']);
        $this->assertStringContainsString('otpauth://totp/', $data['provisioning_uri']);
        $this->assertStringNotContainsString($data['secret'], $user->fresh()->mfa_pending_secret);
        $this->assertSame($data['secret'], Crypt::decryptString($user->fresh()->mfa_pending_secret));

        $code = app(TotpService::class)->codeAt($data['secret'], now()->timestamp);
        $this->postJson('/account/mfa/confirm', ['code' => $code])
            ->assertOk();
        $this->assertDatabaseHas('owner_login_events', [
            'user_id' => $user->id, 'event' => 'mfa_enabled',
        ]);
        $this->assertNull($user->fresh()->mfa_pending_secret);

        return $data['secret'];
    }

    public function test_rfc_6238_six_digit_reference_and_invalid_codes(): void
    {
        $totp = app(TotpService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // RFC 6238 SHA1 fixture
        $this->assertSame('287082', $totp->codeAt($secret, 59));
        $this->assertSame(1, $totp->matchingStep($secret, '287082', 59));
        $this->assertNull($totp->matchingStep($secret, 'not-a-code', 59));
        $this->assertNull($totp->matchingStep($secret, '12345', 59));
        $this->assertNull($totp->matchingStep($secret, '287082', 5900));
    }

    public function test_enrollment_requires_current_password_and_cannot_be_reenabled(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->owner();

        $this->postJson('/account/mfa/start', [
            'current_password' => 'StrongPassword!2026',
        ])->assertUnauthorized();

        $this->actingAs($user)->postJson('/account/mfa/start', [
            'current_password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->enroll($user);
        $this->getJson('/account/mfa')->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonMissingPath('data.secret');
        $this->postJson('/account/mfa/start', [
            'current_password' => 'StrongPassword!2026',
        ])->assertStatus(409);
    }

    public function test_mfa_login_never_creates_authenticated_session_until_valid_second_factor(): void
    {
        // The test deliberately retries within the same 30-second period.
        // Production endpoints retain the 5-per-minute rate-limit middleware.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->owner();
        $secret = $this->enroll($user);
        $this->postJson('/logout')->assertOk();
        $this->travel(31)->seconds();

        $this->postJson('/login', [
            'email' => $user->email, 'password' => 'wrong',
        ])->assertUnprocessable();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'StrongPassword!2026',
        ])->assertOk()->assertJsonPath('mfa_required', true)
            ->assertJsonMissingPath('user');

        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/account/mfa')->assertUnauthorized();

        $this->postJson('/login/mfa', [
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertGuest();
        $this->getJson('/api/v1/properties')->assertUnauthorized();

        $code = app(TotpService::class)->codeAt($secret, now()->timestamp);
        $this->postJson('/login/mfa', [
            'code' => $code,
        ])->assertOk()->assertJsonPath('user.email', $user->email);
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', [
            'email' => $user->email, 'password' => 'StrongPassword!2026',
        ])->assertOk()->assertJsonPath('mfa_required', true);
        $this->postJson('/login/mfa', ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_pending_login_expires_after_five_minutes(): void
    {
        $user = $this->owner();
        $secret = $this->enroll($user);
        $this->postJson('/logout')->assertOk();
        $this->travel(31)->seconds();
        $this->postJson('/login', [
            'email' => $user->email, 'password' => 'StrongPassword!2026',
        ])->assertJsonPath('mfa_required', true);
        $this->travel(6)->minutes();

        $this->postJson('/login/mfa', [
            'code' => app(TotpService::class)->codeAt($secret, now()->timestamp),
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_mfa_can_only_be_disabled_with_password_and_unused_totp(): void
    {
        $user = $this->owner();
        $secret = $this->enroll($user);
        $this->travel(31)->seconds();
        $code = app(TotpService::class)->codeAt($secret, now()->timestamp);

        $this->postJson('/account/mfa/disable', [
            'current_password' => 'wrong', 'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/account/mfa/disable', [
            'current_password' => 'StrongPassword!2026', 'code' => '111111',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/account/mfa/disable', [
            'current_password' => 'StrongPassword!2026', 'code' => $code,
        ])->assertOk();
        $this->getJson('/account/mfa')->assertJsonPath('data.enabled', false);
        $this->assertNull($user->fresh()->mfa_secret);
        $this->assertDatabaseHas('owner_login_events', [
            'user_id' => $user->id, 'event' => 'mfa_disabled',
        ]);
    }

    public function test_mfa_enrollment_expires_and_does_not_activate(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->owner();
        $this->actingAs($user);
        $data = $this->postJson('/account/mfa/start', [
            'current_password' => 'StrongPassword!2026',
        ])->assertOk()->json('data');
        $this->travel(11)->minutes();

        $this->postJson('/account/mfa/confirm', [
            'code' => app(TotpService::class)->codeAt($data['secret'], now()->timestamp),
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($user->fresh()->mfa_enabled_at);
    }

    public function test_mfa_enrollment_fails_closed_without_database_sessions(): void
    {
        config()->set('session.driver', 'array');
        $this->actingAs($this->owner());
        $this->postJson('/account/mfa/start', [
            'current_password' => 'StrongPassword!2026',
        ])->assertStatus(503);
    }
}
