<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_and_revoke_other_sessions_but_not_another_owners_token(): void
    {
        $owner = User::create(['name'=>'Owner','email'=>'owner-sessions@example.test','password'=>'strong-password-for-testing']);
        $other = User::create(['name'=>'Other','email'=>'other-sessions@example.test','password'=>'strong-password-for-testing']);
        $first = $owner->createToken('first', ['*'], now()->addHours(12));
        $second = $owner->createToken('second', ['*'], now()->addHours(12));
        $foreign = $other->createToken('foreign', ['*'], now()->addHours(12));

        $headers = ['Authorization'=>'Bearer '.$first->plainTextToken];
        $this->withHeaders($headers)->getJson('/api/account/sessions')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('current_token_id', $first->accessToken->id);
        $this->withHeaders($headers)->deleteJson('/api/account/sessions/'.$foreign->accessToken->id, [
            'password'=>'strong-password-for-testing',
        ])->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens',['id'=>$foreign->accessToken->id]);
        $this->withHeaders($headers)->deleteJson('/api/account/sessions/'.$second->accessToken->id, [
            'password'=>'wrong-password',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('personal_access_tokens',['id'=>$second->accessToken->id]);
        $this->withHeaders($headers)->deleteJson('/api/account/sessions/others', [
            'password'=>'strong-password-for-testing',
        ])->assertOk()->assertJsonPath('revoked_count',1);
        $this->assertDatabaseMissing('personal_access_tokens',['id'=>$second->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens',['id'=>$first->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens',['id'=>$foreign->accessToken->id]);
    }

    public function test_revoking_current_session_invalidates_its_bearer_token(): void
    {
        $owner = User::create(['name'=>'Owner','email'=>'owner-current@example.test','password'=>'strong-password-for-testing']);
        $token = $owner->createToken('current', ['*'], now()->addHours(12));
        $headers = ['Authorization'=>'Bearer '.$token->plainTextToken];

        $this->withHeaders($headers)->deleteJson('/api/account/sessions/'.$token->accessToken->id, [
            'password'=>'strong-password-for-testing',
        ])->assertOk()->assertJsonPath('current_revoked',true);
        $this->assertDatabaseMissing('personal_access_tokens',['id'=>$token->accessToken->id]);
        // Laravel's HTTP test client reuses the application/auth guard across
        // requests, unlike independent real HTTP requests. Clear cached user
        // authentication so the next request revalidates the revoked token.
        app('auth')->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_unauthenticated_sessions_are_not_accessible(): void
    {
        $this->getJson('/api/account/sessions')->assertUnauthorized();
    }
}
