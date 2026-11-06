<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Lease};
use Illuminate\Foundation\Testing\RefreshDatabase;
class LeaseLifecycleTest extends TestCase {
 use RefreshDatabase;
 private function sample():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'strong-password']);
  $p=$owner->properties()->create(['name'=>'Property','city'=>'Riyadh','type'=>'residential']);
  $u=$p->units()->create(['code'=>'101','type'=>'apartment']);
  $t=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $response=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$u->id,'tenant_id'=>$t->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  return [$owner,Lease::findOrFail($response->json('id'))];
 }
 public function test_cancelled_draft_releases_unit_for_new_lease():void {
  [$owner,$lease]=$this->sample();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/cancel')->assertOk()->assertJsonPath('status','cancelled');
  $this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$lease->unit_id,'tenant_id'=>$lease->tenant_id,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>100,'paid_on'=>'2025-02-01','method'=>'cash'
  ])->assertUnprocessable();
 }
 public function test_cannot_cancel_active_lease():void {
  [$owner,$lease]=$this->sample();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/activate')->assertOk()->assertJsonPath('status','active');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/cancel')->assertUnprocessable();
 }
 public function test_cannot_complete_lease_with_unpaid_installments():void {
  [$owner,$lease]=$this->sample();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/activate')->assertOk();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/complete')->assertUnprocessable();
 }
 public function test_paid_and_expired_lease_can_be_completed():void {
  [$owner,$lease]=$this->sample();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/activate')->assertOk();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'bank_transfer'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/complete')->assertOk()->assertJsonPath('status','completed');
 }
 public function test_cross_owner_cannot_activate_lease():void {
  [$owner,$lease]=$this->sample();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'strong-password']);
  $this->actingAs($other)->postJson('/api/leases/'.$lease->id.'/activate')->assertNotFound();
 }
}
