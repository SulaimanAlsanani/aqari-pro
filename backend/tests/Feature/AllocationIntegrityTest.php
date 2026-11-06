<?php
namespace Tests\Feature;
use App\Models\{User,PaymentAllocation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationIntegrityTest extends TestCase {
 use RefreshDatabase;
 private function lease(User $owner,string $code):int {
  $property=$owner->properties()->create(['name'=>'Tower '.$code,'city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>$code,'type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant '.$code,'phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  return (int)$lease;
 }
 private function settle(int $lease):int {
  return (int)$this->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'cash'
  ])->assertCreated()->json('id');
 }
 public function test_overpaying_one_due_and_underpaying_another_blocks_completion():void {
  $owner=User::create(['name'=>'Owner','email'=>'one@example.test','password'=>'password']);
  $lease=$this->lease($owner,'A1');
  $payment=$this->settle($lease);
  $allocations=PaymentAllocation::where('payment_id',$payment)->orderBy('id')->get();
  $this->assertCount(12,$allocations);
  $allocations[0]->update(['amount'=>1500]);
  $allocations[1]->update(['amount'=>500]);
  // Aggregate amounts still appear equal but an installment is overpaid.
  $this->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('rent.received','12000.00')
   ->assertJsonPath('rent.allocated','12000.00')
   ->assertJsonPath('rent.balanced',false)
   ->assertJsonPath('ready_for_financial_closure',false);
  $this->postJson('/api/leases/'.$lease.'/complete')
   ->assertUnprocessable()->assertJsonValidationErrors('status');
 }
 public function test_cross_lease_allocation_is_detected_even_with_equal_totals():void {
  $owner=User::create(['name'=>'Owner','email'=>'two@example.test','password'=>'password']);
  $firstLease=$this->lease($owner,'A1');
  $secondLease=$this->lease($owner,'B1');
  $firstReceipt=$this->settle($firstLease);
  $secondReceipt=$this->settle($secondLease);
  $a=PaymentAllocation::where('payment_id',$firstReceipt)->orderBy('id')->firstOrFail();
  $b=PaymentAllocation::where('payment_id',$secondReceipt)->orderBy('id')->firstOrFail();
  $firstDue=$a->due_id;
  $a->update(['due_id'=>$b->due_id]);
  $b->update(['due_id'=>$firstDue]);
  foreach([$firstLease,$secondLease] as $lease){
   $this->getJson('/api/leases/'.$lease.'/settlement')
    ->assertOk()->assertJsonPath('rent.balanced',false)
    ->assertJsonPath('ready_for_financial_closure',false);
   $this->postJson('/api/leases/'.$lease.'/complete')->assertUnprocessable();
  }
 }
}
