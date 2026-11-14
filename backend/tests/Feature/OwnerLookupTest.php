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

    public function test_unit_choices_are_restricted_to_selected_property_and_owner(): void
    {
        $owner=$this->makeOwner('property-filter@example.test');
        $other=$this->makeOwner('property-filter-other@example.test');
        $one=$owner->properties()->create(['name'=>'Building A','city'=>'Riyadh','type'=>'residential']);
        $two=$owner->properties()->create(['name'=>'Building B','city'=>'Riyadh','type'=>'residential']);
        $foreign=$other->properties()->create(['name'=>'Building C','city'=>'Riyadh','type'=>'residential']);
        $unitOne=$one->units()->create(['code'=>'A-101','type'=>'apartment']);
        $two->units()->create(['code'=>'B-101','type'=>'apartment']);
        $foreign->units()->create(['code'=>'C-101','type'=>'apartment']);
        $this->actingAs($owner,'sanctum');

        $this->getJson('/api/lookups/units?property_id='.$one->id)->assertOk()
            ->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$unitOne->id);
        $this->getJson('/api/lookups/units?property_id='.$foreign->id)->assertOk()
            ->assertJsonCount(0,'data');
        $this->getJson('/api/lookups/units?property_id=nope')->assertUnprocessable()
            ->assertJsonValidationErrors('property_id');
    }

    public function test_receipt_picker_excludes_cancelled_and_completed_leases(): void
    {
        $owner=$this->makeOwner('lease-filter@example.test');
        $building=$owner->properties()->create(['name'=>'Sunrise','city'=>'Riyadh','type'=>'residential']);
        $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
        $ids=[];
        foreach (['draft','active','cancelled','completed'] as $status) {
            $unit=$building->units()->create(['code'=>'Unit-'.$status,'type'=>'apartment']);
            $lease=$unit->leases()->create([
                'tenant_id'=>$tenant->id,'starts_on'=>'2027-01-01','ends_on'=>'2027-12-31',
                'total_amount'=>'1200.00','deposit'=>'0.00','status'=>$status
            ]);
            $lease->dues()->create([
                'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'1200.00'
            ]);
            $ids[$status]=$lease->id;
        }
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/lookups/leases')->assertOk()->assertJsonCount(4,'data');
        $result=$this->getJson('/api/lookups/leases?receivable=1')->assertOk()
            ->assertJsonCount(2,'data')->json('data');
        self::assertSame([$ids['draft'],$ids['active']],array_column($result,'id'));
    }

    public function test_receipt_picker_excludes_paid_lease_and_restores_voided_receipt_balance(): void
    {
        $owner=$this->makeOwner('picker-balance@example.test');
        $property=$owner->properties()->create([
            'name'=>'Balance Tower','city'=>'Riyadh','type'=>'residential'
        ]);
        $unit=$property->units()->create(['code'=>'P-9','type'=>'apartment']);
        $tenant=$owner->tenants()->create([
            'name'=>'Payer','phone'=>'0500000000'
        ]);
        $lease=$unit->leases()->create([
            'tenant_id'=>$tenant->id,'starts_on'=>'2027-01-01',
            'ends_on'=>'2027-12-31','total_amount'=>'1200.00',
            'deposit'=>'0.00','status'=>'active'
        ]);
        foreach (['2027-01-01','2027-07-01'] as $index=>$date) {
            $lease->dues()->create([
                'sequence'=>$index+1,'due_on'=>$date,'amount'=>'600.00'
            ]);
        }
        $this->actingAs($owner,'sanctum');
        $picker='/api/lookups/leases?receivable=1';
        $this->getJson($picker)->assertOk()->assertJsonCount(1,'data');

        $this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'600.00','paid_on'=>'2027-01-01','method'=>'cash'
        ])->assertCreated();
        $this->getJson($picker)->assertOk()->assertJsonCount(1,'data');

        $last=$this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'600.00','paid_on'=>'2027-07-01','method'=>'cash'
        ])->assertCreated()->json('id');
        $this->getJson($picker)->assertOk()->assertJsonCount(0,'data');

        $this->postJson('/api/payments/'.$last.'/void',[
            'reason'=>'Synthetic receipt entered in error'
        ])->assertOk();
        $this->getJson($picker)->assertOk()
            ->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$lease->id);
    }

    public function test_search_supports_city_tenant_phone_and_vendor_service(): void
    {
        $owner=$this->makeOwner('lookup-multi@example.test');
        $owner->properties()->create(['name'=>'Residence','city'=>'Dammam','type'=>'residential']);
        $owner->tenants()->create(['name'=>'Tenant Person','phone'=>'0551234567']);
        $owner->vendors()->create(['name'=>'Expert Co','service'=>'Electrical maintenance']);
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/lookups/properties?q=Dammam')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/lookups/tenants?q=055123')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/lookups/vendors?q=Electrical')->assertOk()->assertJsonCount(1,'data');
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
