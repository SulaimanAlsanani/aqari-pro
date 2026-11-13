<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerLookupTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(string $email): User
    {
        return User::create(['name'=>'Lookup owner','email'=>$email,'password'=>'strong-password-for-testing']);
    }

    public function test_all_lookup_types_show_only_current_owner_records(): void
    {
        $owner=$this->makeOwner('lookup-owner@example.test');
        $foreign=$this->makeOwner('lookup-foreign@example.test');
        foreach ([$owner,$foreign] as $user) {
            $suffix=$user->is($owner)?'Own':'Foreign';
            $property=$user->properties()->create(['name'=>$suffix.' Tower','city'=>'Riyadh','type'=>'residential']);
            $unit=$property->units()->create(['code'=>$suffix.'-101','type'=>'apartment']);
            $tenant=$user->tenants()->create(['name'=>$suffix.' Tenant','phone'=>'0500000000']);
            $unit->leases()->create([
                'tenant_id'=>$tenant->id,
                'starts_on'=>'2027-01-01','ends_on'=>'2027-12-31',
                'total_amount'=>'1200.00','deposit'=>'0.00','status'=>'draft'
            ]);
            $user->vendors()->create(['name'=>$suffix.' Vendor','service'=>'Maintenance']);
        }
        $this->actingAs($owner,'sanctum');

        foreach ([
            'properties'=>['Own Tower','Foreign Tower'],
            'units'=>['Own-101','Foreign-101'],
            'tenants'=>['Own Tenant','Foreign Tenant'],
            'leases'=>['Own-101','Foreign-101'],
            'vendors'=>['Own Vendor','Foreign Vendor']
        ] as $resource=>[$allowed,$forbidden]) {
            $result=$this->getJson('/api/lookups/'.$resource)->assertOk()
                ->assertJsonCount(1,'data')->assertJsonPath('has_more',false);
            self::assertStringContainsString($allowed,$result->json('data.0.label'));
            self::assertStringNotContainsString($forbidden,$result->json('data.0.label'));
        }
    }

    public function test_record_search_and_result_size_are_bounded(): void
    {
        $owner=$this->makeOwner('lookup-search@example.test');
        for ($i=0;$i<23;$i++) {
            $owner->properties()->create([
                'name'=>'Rental Tower '.str_pad((string)$i,2,'0',STR_PAD_LEFT),
                'city'=>'Riyadh','type'=>'residential'
            ]);
        }
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/lookups/properties')->assertOk()
            ->assertJsonCount(20,'data')->assertJsonPath('has_more',true);
        $this->getJson('/api/lookups/properties?q=Tower%2022')->assertOk()
            ->assertJsonCount(1,'data')->assertJsonPath('has_more',false);
        $this->getJson('/api/lookups/properties?q=Absent')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/lookups/properties?q=%25')->assertOk()->assertJsonCount(0,'data');
    }

    public function test_lookup_refuses_guest_bad_resource_and_unbounded_query(): void
    {
        $this->getJson('/api/lookups/properties')->assertUnauthorized();
        $this->actingAs($this->makeOwner('lookup-validation@example.test'),'sanctum');
        $this->getJson('/api/lookups/users')->assertNotFound();
        $this->getJson('/api/lookups/properties?q='.str_repeat('z',81))
            ->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/lookups/properties?q[]=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_owner_can_find_unit_by_property_name_and_lease_by_tenant_name(): void
    {
        $owner=$this->makeOwner('lookup-related@example.test');
        $building=$owner->properties()->create(['name'=>'Palm Residence','city'=>'Jeddah','type'=>'residential']);
        $unit=$building->units()->create(['code'=>'A-31','type'=>'apartment']);
        $tenant=$owner->tenants()->create(['name'=>'Salem Resident','phone'=>'0500000000']);
        $unit->leases()->create([
            'tenant_id'=>$tenant->id,'starts_on'=>'2027-01-01','ends_on'=>'2027-12-31',
            'total_amount'=>'1200.00','deposit'=>'0.00','status'=>'draft'
        ]);
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/lookups/units?q=Palm')->assertOk()
            ->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$unit->id);
        $this->getJson('/api/lookups/leases?q=Salem')->assertOk()
            ->assertJsonCount(1,'data');
    }
}
