<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
 use RefreshDatabase;

 public function test_login_throttle_follows_normalized_account_across_source_ips(): void
 {
  $email='throttle-target-'.uniqid().'@example.test';
  for($i=0;$i<5;$i++){
   $this->withServerVariables(['REMOTE_ADDR'=>$i%2===0?'198.51.100.10':'198.51.100.11'])
    ->postJson('/api/login',[
     'email'=>$i%2===0?strtoupper($email):'  '.$email.'  ',
     'password'=>'not-the-password',
    ])->assertUnprocessable();
  }
  $this->withServerVariables(['REMOTE_ADDR'=>'198.51.100.12'])
   ->postJson('/api/login',['email'=>$email,'password'=>'not-the-password'])
   ->assertStatus(429);
 }

 public function test_throttle_does_not_block_a_different_account_on_the_same_ip(): void
 {
  $ip='198.51.100.20';
  $target='throttle-blocked-'.uniqid().'@example.test';
  for($i=0;$i<5;$i++){
   $this->withServerVariables(['REMOTE_ADDR'=>$ip])
    ->postJson('/api/login',['email'=>$target,'password'=>'wrong'])
    ->assertUnprocessable();
  }
  $this->withServerVariables(['REMOTE_ADDR'=>$ip])
   ->postJson('/api/login',['email'=>$target,'password'=>'wrong'])
   ->assertStatus(429);
  $this->withServerVariables(['REMOTE_ADDR'=>$ip])
   ->postJson('/api/login',['email'=>'unrelated-'.uniqid().'@example.test','password'=>'wrong'])
   ->assertUnprocessable();
 }
}
