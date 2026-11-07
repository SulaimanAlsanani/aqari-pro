<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class AuthenticationTest extends TestCase{
 use RefreshDatabase;
 public function test_login_issues_expiring_bearer_token():void{
  User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'secure-password-123']);
  $response=$this->postJson('/api/login',[
   'email'=>'owner@example.test','password'=>'secure-password-123'
  ])->assertOk()->assertJsonPath('token_type','Bearer')->assertJsonPath('expires_in',43200);
  $token=$response->json('token');
  $this->assertNotEmpty($token);
  $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('email','owner@example.test');
 }
 public function test_invalid_login_is_rejected():void{
  User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'secure-password-123']);
  $this->postJson('/api/login',['email'=>'owner@example.test','password'=>'bad-password'])
   ->assertUnprocessable();
 }
}
