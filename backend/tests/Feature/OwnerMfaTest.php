<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_second_factor_once_enabled(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'a-long-test-password']);
        $this->postJson('/api/login',['email'=>$user->email,'password'=>'a-long-test-password'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->actingAs($user,'sanctum');
        $setup=$this->postJson('/api/account/mfa/setup',['password'=>'a-long-test-password'])->assertOk()->json();
        $this->postJson('/api/account/mfa/confirm',['code'=>Totp::code($setup['secret'],time())])
            ->assertOk()->assertJsonStructure(['recovery_codes']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login',['email'=>$user->email,'password'=>'a-long-test-password'])
            ->assertUnprocessable()->assertJsonMissingPath('token');
        $this->postJson('/api/login',['email'=>$user->email,'password'=>'a-long-test-password','mfa_code'=>'000000'])
            ->assertUnprocessable()->assertJsonMissingPath('token');

        // The confirmation time-step is consumed; a recovery code is usable once.
        $codes=$user->fresh()->mfa_recovery_codes;
        $this->assertCount(8,$codes);
        $this->assertNotContains('0000000000000000',$codes);
    }

    public function test_pending_setup_does_not_enable_mfa(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'pending@example.test','password'=>'a-long-test-password']);
        $this->actingAs($user,'sanctum');
        $this->postJson('/api/account/mfa/setup',['password'=>'a-long-test-password'])->assertOk();
        $this->getJson('/api/account/mfa')->assertOk()->assertJson(['enabled'=>false]);
        $this->postJson('/api/account/mfa/confirm',['code'=>'000000'])->assertUnprocessable();
        $this->assertNull($user->fresh()->mfa_enabled_at);
    }
    public function test_recovery_code_is_single_use_and_disabling_revokes_tokens(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'recovery@example.test','password'=>'a-long-test-password']);
        $this->actingAs($user,'sanctum');
        $setup=$this->postJson('/api/account/mfa/setup',['password'=>'a-long-test-password'])->assertOk()->json();
        $recovery=$this->postJson('/api/account/mfa/confirm',['code'=>Totp::code($setup['secret'],time())])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(8,$recovery);

        $this->app['auth']->forgetGuards();
        $credentials=['email'=>$user->email,'password'=>'a-long-test-password','mfa_code'=>$recovery[0]];
        $this->postJson('/api/login',$credentials)->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/login',$credentials)->assertUnprocessable();
        $this->assertCount(7,$user->fresh()->mfa_recovery_codes);

        $this->actingAs($user,'sanctum');
        $this->postJson('/api/account/mfa/disable',[
            'password'=>'a-long-test-password','code'=>$recovery[1],
        ])->assertOk()->assertJson(['enabled'=>false]);
        $this->assertNull($user->fresh()->mfa_secret);
        $this->assertNull($user->fresh()->mfa_recovery_codes);
    }

}
