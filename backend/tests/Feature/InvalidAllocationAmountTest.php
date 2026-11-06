<?php
namespace Tests\Feature;

use App\Models\{User,PaymentAllocation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvalidAllocationAmountTest extends TestCase {
 use RefreshDatabase;

 private function fixture():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=(int)$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  $payment=(int)$this->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>12000,'method'=>'cash','paid_on'=>'2025-12-31'
  ])->assertCreated()->json('id');
  return [$lease,$payment];
 }

 public function test_offsetting_negative_allocation_cannot_hide_with_equal_aggregate():void {
  [$lease,$payment]=$this->fixture();
  $allocations=PaymentAllocation::where('payment_id',$payment)->orderBy('id')->get();
  $this->assertCount(12,$allocations);
  // One negative row is offset by an overpayment elsewhere: aggregate remains unchanged.
  $allocations[0]->update(['amount'=>-100]);
  $allocations[1]->update(['amount'=>2100]);
  $this->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('rent.balanced',false)
   ->assertJsonPath('ready_for_financial_closure',false);
  $this->postJson('/api/leases/'.$lease.'/complete')->assertUnprocessable();
  $this->assertContains('ALLOCATION_INTEGRITY_FAILED',
   array_column($this->getJson('/api/reconciliation')->assertOk()->json('issues'),'code'));
 }

 public function test_zero_allocation_is_invalid_even_if_other_receipts_compensate():void {
  [$lease,$payment]=$this->fixture();
  $allocations=PaymentAllocation::where('payment_id',$payment)->orderBy('id')->get();
  $allocations[0]->update(['amount'=>0]);
  $allocations[1]->update(['amount'=>2000]);
  $this->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('rent.balanced',false);
  $this->postJson('/api/leases/'.$lease.'/complete')->assertUnprocessable();
 }
}
