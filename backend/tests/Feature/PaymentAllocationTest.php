<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Lease,PaymentAllocation};
use Illuminate\Foundation\Testing\RefreshDatabase;
class PaymentAllocationTest extends TestCase {
 use RefreshDatabase;
 private function setupLease():array{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Test Property','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $r=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  return [$owner,Lease::findOrFail($r->json('id'))];
 }
 public function test_partial_receipt_is_allocated_to_oldest_due():void{
  [$owner,$lease]=$this->setupLease();
  $r=$this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>1500,'paid_on'=>'2026-02-01','method'=>'bank_transfer'
  ])->assertCreated();
  $this->assertSame(2,PaymentAllocation::where('payment_id',$r->json('id'))->count());
  $this->assertEqualsWithDelta(1000,(float)$lease->dues()->first()->allocations()->sum('amount'),0.001);
  $this->assertEqualsWithDelta(500,(float)$lease->dues()->orderBy('sequence')->skip(1)->first()->allocations()->sum('amount'),0.001);
 }
 public function test_overpayment_fails_without_creating_partial_ledger():void{
  [$owner,$lease]=$this->setupLease();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>12001,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertUnprocessable()->assertJsonValidationErrors('amount');
  $this->assertDatabaseCount('payments',0);
  $this->assertDatabaseCount('payment_allocations',0);
 }
 public function test_more_than_two_decimal_places_is_rejected():void{
  [$owner,$lease]=$this->setupLease();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>19.999,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertUnprocessable()->assertJsonValidationErrors('amount');
 }
 public function test_another_owner_cannot_create_receipt():void{
  [$owner,$lease]=$this->setupLease();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>100,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertNotFound();
 }
}
