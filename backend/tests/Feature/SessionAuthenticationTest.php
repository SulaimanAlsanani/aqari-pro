<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_create_session_and_expose_minimal_owner_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('TestingPassword!123'),
        ]);
        Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Portfolio Owner']);

        $this->postJson('/login', [
            'email' => 'owner@example.test',
            'password' => 'TestingPassword!123',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@example.test')
            ->assertJsonPath('owner.name', 'Portfolio Owner')
            ->assertJsonMissingPath('user.password');
    }

    public function test_invalid_password_cannot_create_authenticated_session(): void
    {
        User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('CorrectPassword!123'),
        ]);

        $this->postJson('/login', [
            'email' => 'owner@example.test',
            'password' => 'IncorrectPassword',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_session_and_disables_protected_api(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('TestingPassword!123'),
        ]);

        $this->postJson('/login', [
            'email' => 'owner@example.test',
            'password' => 'TestingPassword!123',
        ])->assertOk();

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_guest_cannot_log_out_or_read_private_profile(): void
    {
        $this->postJson('/logout')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
