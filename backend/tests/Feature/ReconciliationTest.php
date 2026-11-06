<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\PaymentAllocation;

class ReconciliationTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'A101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  return [$owner,$lease->json('id')];
 }
 public function test_balanced_and_reversed_receipt_reconciles_without_leakage():void {
  [$owner,$lease]=$this->fixture();
  $receipt=$this->actingAs($owner)->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>1500,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertCreated();
  $this->actingAs($owner)->getJson('/api/reconciliation')->assertOk()
   ->assertJsonPath('data.0.balanced',true)
   ->assertJsonPath('data.0.received','1500.00')
   ->assertJsonPath('data.0.allocated','1500.00')
   ->assertJsonPath('data.0.outstanding','10500.00')
   ->assertJsonCount(0,'issues');
  $this->actingAs($owner)->postJson('/api/payments/'.$receipt->json('id').'/void',[
   'reason'=>'سند قبض مسجل بطريق الخطأ'
  ])->assertOk();
  $this->actingAs($owner)->getJson('/api/reconciliation')->assertOk()
   ->assertJsonPath('data.0.balanced',true)
   ->assertJsonPath('data.0.received','0.00')
   ->assertJsonPath('data.0.outstanding','12000.00');
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->getJson('/api/reconciliation')->assertOk()->assertJsonCount(0,'data');
 }
 public function test_corrupt_allocation_is_flagged():void {
  [$owner,$lease]=$this->fixture();
  $receipt=$this->actingAs($owner)->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>500,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertCreated();
  PaymentAllocation::where('payment_id',$receipt->json('id'))->update(['amount'=>400]);
  $result=$this->actingAs($owner)->getJson('/api/reconciliation')->assertOk();
  $result->assertJsonPath('data.0.balanced',false);
  $this->assertContains('PAYMENT_ALLOCATION_MISMATCH',array_column($result->json('issues'),'code'));
 }
}
