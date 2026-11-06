<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeaseSettlementTest extends TestCase {
 use RefreshDatabase;
 private function sample():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'deposit'=>1000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  return [$owner,$lease];
 }
 public function test_settlement_remains_pending_while_rent_or_deposit_are_outstanding():void {
  [$owner,$lease]=$this->sample();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-10'
  ])->assertCreated();
  $data=$this->actingAs($owner)->getJson('/api/leases/'.$lease.'/settlement')->assertOk();
  $data->assertJsonPath('rent.outstanding','12000.00')
   ->assertJsonPath('deposit.held','1000.00')
   ->assertJsonPath('ready_for_financial_closure',false);
  $this->assertContains('RENT_OUTSTANDING',$data->json('review_flags'));
  $this->assertContains('DEPOSIT_HELD',$data->json('review_flags'));
 }
 public function test_settlement_is_ready_once_rent_paid_and_deposit_returned():void {
  [$owner,$lease]=$this->sample();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'bank_transfer'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-10'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'refund','amount'=>1000,'occurred_on'=>'2026-11-05'
  ])->assertCreated();
  $this->actingAs($owner)->getJson('/api/leases/'.$lease.'/settlement')->assertOk()
   ->assertJsonPath('rent.received','12000.00')
   ->assertJsonPath('rent.allocated','12000.00')
   ->assertJsonPath('deposit.held','0.00')
   ->assertJsonPath('ready_for_financial_closure',true)
   ->assertJsonCount(0,'review_flags');
 }
 public function test_voided_payment_does_not_count_toward_closure():void {
  [$owner,$lease]=$this->sample();
  $payment=$this->actingAs($owner)->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'cash'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/payments/'.$payment->json('id').'/void',[
   'reason'=>'سند أدخل بطريق الخطأ وتمت مراجعته'
  ])->assertOk();
  $this->actingAs($owner)->getJson('/api/leases/'.$lease.'/settlement')->assertOk()
   ->assertJsonPath('rent.received','0.00')
   ->assertJsonPath('rent.outstanding','12000.00')
   ->assertJsonPath('ready_for_financial_closure',false);
 }
 public function test_another_owner_cannot_view_settlement():void {
  [$owner,$lease]=$this->sample();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->getJson('/api/leases/'.$lease.'/settlement')->assertNotFound();
 }
}
