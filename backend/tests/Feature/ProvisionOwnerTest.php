<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_interactive_command_provisions_linked_owner_with_hashed_password(): void
    {
        $this->artisan('aqari:provision-owner', [
            'email' => 'demo.owner@example.test',
            '--name' => 'Demo Owner',
        ])
            ->expectsQuestion('New password (at least 12 characters)', 'UniqueLongPassword!123')
            ->expectsQuestion('Confirm new password', 'UniqueLongPassword!123')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'demo.owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('UniqueLongPassword!123', $user->password));
        $this->assertNotSame('UniqueLongPassword!123', $user->password);
        $this->assertDatabaseHas('owners', [
            'user_id' => $user->id, 'name' => 'Demo Owner',
        ]);
    }

    public function test_password_confirmation_failure_does_not_create_owner(): void
    {
        $this->artisan('aqari:provision-owner', [
            'email' => 'unverified@example.test',
            '--name' => 'Unverified Owner',
        ])
            ->expectsQuestion('New password (at least 12 characters)', 'UniqueLongPassword!123')
            ->expectsQuestion('Confirm new password', 'WrongPassword!123')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.test']);
        $this->assertDatabaseCount('owners', 0);
    }

    public function test_duplicate_email_is_rejected_before_password_prompt(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->artisan('aqari:provision-owner', [
            'email' => 'existing@example.test',
            '--name' => 'Duplicate Owner',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('owners', 0);
    }
}
