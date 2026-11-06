<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class PasswordRotationTest extends TestCase {
 use RefreshDatabase;
 public function test_password_change_rejects_wrong_existing_password():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'old-password-1234']);
  $this->actingAs($owner)->postJson('/api/account/change-password',[
   'current_password'=>'wrong-password',
   'password'=>'new-secure-password-1234','password_confirmation'=>'new-secure-password-1234'
  ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
 }
 public function test_changing_password_revokes_existing_api_tokens():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'old-password-1234']);
  $token=$owner->createToken('browser')->plainTextToken;
  $this->withToken($token)->postJson('/api/account/change-password',[
   'current_password'=>'old-password-1234',
   'password'=>'new-secure-password-1234','password_confirmation'=>'new-secure-password-1234'
  ])->assertOk();
  $this->assertDatabaseCount('personal_access_tokens',0);
  $this->app['auth']->forgetGuards();
  $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
  $this->postJson('/api/login',['email'=>'owner@example.test','password'=>'new-secure-password-1234'])->assertOk();
 }
}
