<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Lease};
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeaseRenewalTest extends TestCase {
 use RefreshDatabase;
 private function leaseFixture():array{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  return [$owner,Lease::findOrFail($lease->json('id'))];
 }
 public function test_renewal_creates_linked_draft_with_twelve_balanced_dues():void{
  [$owner,$lease]=$this->leaseFixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/activate')->assertOk();
  $response=$this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/renew',[
   'total_amount'=>10000,'installments'=>12,'deposit'=>500
  ])->assertCreated()->assertJsonPath('status','draft')
   ->assertJsonPath('renewed_from_id',$lease->id)
   ->assertJsonPath('starts_on','2026-01-01T00:00:00.000000Z')
   ->assertJsonCount(12,'dues');
  $new=Lease::findOrFail($response->json('id'));
  $this->assertSame($lease->tenant_id,$new->tenant_id);
  $this->assertSame($lease->unit_id,$new->unit_id);
  $this->assertSame('2026-12-31',$new->ends_on->toDateString());
  $this->assertSame(1000000,(int)round($new->dues()->sum('amount')*100));
 }
 public function test_renewal_refuses_second_renewal():void{
  [$owner,$lease]=$this->leaseFixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/activate')->assertOk();
  $input=['total_amount'=>12000,'installments'=>4];
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/renew',$input)->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/renew',$input)
   ->assertUnprocessable();
 }
 public function test_cannot_renew_another_owners_lease():void{
  [$owner,$lease]=$this->leaseFixture();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->postJson('/api/leases/'.$lease->id.'/renew',[
   'total_amount'=>12000,'installments'=>12
  ])->assertNotFound();
 }
 public function test_renewal_refuses_conflicting_next_lease():void{
  [$owner,$lease]=$this->leaseFixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/activate')->assertOk();
  $this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$lease->unit_id,'tenant_id'=>$lease->tenant_id,
   'starts_on'=>'2026-04-01','ends_on'=>'2027-03-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/renew',[
   'total_amount'=>12000,'installments'=>12
  ])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
 }
}
