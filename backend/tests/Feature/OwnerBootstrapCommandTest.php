<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_create_prompts_for_secret_password_and_hashes_it(): void
    {
        $this->artisan('owner:create', ['email' => 'OWNER@EXAMPLE.TEST', 'name' => 'Owner One'])
            ->expectsQuestion('Owner password (at least 12 characters)', 'a-very-long-secret-123')
            ->expectsQuestion('Confirm owner password', 'a-very-long-secret-123')
            ->expectsOutput('Owner account created successfully.')
            ->assertSuccessful();

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('Owner One', $owner->name);
        $this->assertNotSame('a-very-long-secret-123', $owner->password);
        $this->assertTrue(Hash::check('a-very-long-secret-123', $owner->password));
    }

    public function test_invalid_owner_email_does_not_create_account(): void
    {
        $this->artisan('owner:create', ['email' => 'invalid-email', 'name' => 'Owner'])
            ->expectsOutput('Invalid owner name or email.')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_email_does_not_replace_existing_password(): void
    {
        $existing = User::create(['name' => 'Existing', 'email' => 'existing@example.test', 'password' => 'original-password-123']);
        $hash = $existing->password;
        $this->artisan('owner:create', ['email' => 'existing@example.test', 'name' => 'Another'])
            ->expectsOutput('An account already exists for this email.')
            ->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_short_or_mismatched_password_is_rejected(): void
    {
        $this->artisan('owner:create', ['email' => 'short@example.test', 'name' => 'Owner'])
            ->expectsQuestion('Owner password (at least 12 characters)', 'short')
            ->expectsQuestion('Confirm owner password', 'short')
            ->expectsOutput('Password must be at least 12 characters and match its confirmation.')
            ->assertFailed();

        $this->artisan('owner:create', ['email' => 'mismatch@example.test', 'name' => 'Owner'])
            ->expectsQuestion('Owner password (at least 12 characters)', 'password-long-123')
            ->expectsQuestion('Confirm owner password', 'password-long-456')
            ->expectsOutput('Password must be at least 12 characters and match its confirmation.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
