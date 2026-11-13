<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StagingE2eReadinessTest extends TestCase
{
    use RefreshDatabase;

    // This fixture temporarily changes the default connection to an isolated
    // connection named mysql, backed by the same in-memory SQLite PDO. Restore
    // the original before RefreshDatabase rolls back its test transaction;
    // otherwise the next test sees "There is already an active transaction".
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection=(string)config('database.default');
    }

    protected function tearDown(): void
    {
        config()->set('database.default',$this->originalConnection);
        // The synthetic mysql connection shares the test PDO and must not
        // outlive this test or interfere with later cases.
        if (app()->bound('db')) DB::disconnect('mysql');
        parent::tearDown();
    }

    public function test_production_and_default_configuration_never_expose_write_readiness(): void
    {
        $user=User::create(['name'=>'E2E test','email'=>'aqari-e2e-owner@example.test','password'=>'test-password-long']);
        $this->actingAs($user,'sanctum');
        $this->getJson('/api/staging/e2e-readiness')->assertNotFound();

        config()->set('staging.e2e_write_enabled',true);
        config()->set('staging.e2e_owner_email',$user->email);
        $this->getJson('/api/staging/e2e-readiness')->assertNotFound();
    }

    public function test_staging_denies_unapproved_accounts_before_querying_data(): void
    {
        $user=User::create(['name'=>'Other owner','email'=>'owner@example.test','password'=>'test-password-long']);
        $this->actingAs($user,'sanctum');
        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('app.url','https://staging.example.test');
        config()->set('app.debug',false);
        config()->set('staging.e2e_write_enabled',true);
        config()->set('staging.e2e_owner_email','aqari-e2e-owner@example.test');
        $this->getJson('/api/staging/e2e-readiness')->assertNotFound();
    }

    public function test_guest_cannot_read_staging_gate(): void
    {
        $this->getJson('/api/staging/e2e-readiness')->assertUnauthorized();
    }

    public function test_empty_dedicated_owner_is_allowed_once_and_populated_owner_is_blocked(): void
    {
        $user=User::create([
            'name'=>'Dedicated E2E staging owner',
            'email'=>'aqari-e2e-owner@example.test',
            'password'=>'test-password-long',
        ]);
        $this->actingAs($user,'sanctum');

        // Share the pre-migrated in-memory SQLite PDO for the test only. The
        // connection is named mysql to exercise the deployment config checks;
        // no external MySQL or production database is contacted.
        $pdo=DB::connection('sqlite')->getPdo();
        config()->set('database.connections.mysql',[
            'driver'=>'mysql','url'=>null,'host'=>'db','port'=>'3306',
            'database'=>'aqari_staging','username'=>'aqari_staging','password'=>'not-used',
            'prefix'=>'','strict'=>true,
        ]);
        DB::purge('mysql');
        DB::connection('mysql')->setPdo($pdo);
        DB::connection('mysql')->setReadPdo($pdo);
        config()->set('database.default','mysql');

        $this->app->detectEnvironment(fn()=> 'staging');
        config()->set('app.env','staging');
        config()->set('app.url','https://staging.example.test');
        config()->set('app.debug',false);
        config()->set('staging.e2e_write_enabled',true);
        config()->set('staging.e2e_owner_email',$user->email);

        $this->getJson('/api/staging/e2e-readiness')->assertOk()
            ->assertJson(['allowed'=>true,'environment'=>'staging','owner_id'=>$user->id]);

        // Any other user is evidence of a shared or previously used database.
        $other=User::create([
            'name'=>'Unrelated owner',
            'email'=>'someone-else@example.test',
            'password'=>'test-password-long',
        ]);
        $this->getJson('/api/staging/e2e-readiness')->assertStatus(409)
            ->assertJson(['allowed'=>false]);
        $other->delete();

        // Any existing domain record blocks another non-idempotent run.
        $user->properties()->create([
            'name'=>'Previously written synthetic property',
            'city'=>'Riyadh','type'=>'residential',
        ]);
        $this->getJson('/api/staging/e2e-readiness')->assertStatus(409)
            ->assertJson(['allowed'=>false]);
    }
}
