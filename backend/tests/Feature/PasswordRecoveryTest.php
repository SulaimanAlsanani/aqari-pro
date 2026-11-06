<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Notifications\ResetPassword;

class PasswordRecoveryTest extends TestCase{
 use RefreshDatabase;
 public function test_reset_link_request_is_generic_for_existing_and_unknown_email():void{
  Notification::fake();
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'initial-password-1234']);
  $known=$this->postJson('/api/password/forgot',['email'=>$owner->email])->assertOk()->json('message');
  $unknown=$this->postJson('/api/password/forgot',['email'=>'missing@example.test'])->assertOk()->json('message');
  $this->assertSame($known,$unknown);
  Notification::assertSentTo($owner,ResetPassword::class);
 }
 public function test_valid_reset_changes_password_and_revokes_all_tokens():void{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'initial-password-1234']);
  $owner->createToken('browser');
  $token=Password::broker()->createToken($owner);
  $this->postJson('/api/password/reset',[
   'email'=>$owner->email,'token'=>$token,'password'=>'brand-new-password-5678',
   'password_confirmation'=>'brand-new-password-5678'
  ])->assertOk();
  $this->assertTrue(Hash::check('brand-new-password-5678',$owner->fresh()->password));
  $this->assertDatabaseCount('personal_access_tokens',0);
  $this->postJson('/api/password/reset',[
   'email'=>$owner->email,'token'=>$token,'password'=>'another-password-5678',
   'password_confirmation'=>'another-password-5678'
  ])->assertUnprocessable()->assertJsonValidationErrors('token');
 }
 public function test_wrong_token_is_rejected():void{
  User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'initial-password-1234']);
  $this->postJson('/api/password/reset',[
   'email'=>'owner@example.test','token'=>'invalid-token',
   'password'=>'new-secure-password-1234',
   'password_confirmation'=>'new-secure-password-1234'
  ])->assertUnprocessable()->assertJsonValidationErrors('token');
 }
}
