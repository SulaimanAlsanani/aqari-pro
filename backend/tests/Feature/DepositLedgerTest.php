<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DepositLedgerTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'deposit'=>2000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  return [$owner,$lease];
 }
 public function test_receipt_and_return_are_separate_from_rent_income():void {
  [$owner,$lease]=$this->fixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>2000,'occurred_on'=>'2025-01-01'
  ])->assertCreated()->assertJsonPath('totals.held','2000.00');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'refund','amount'=>1500,'occurred_on'=>'2026-11-05'
  ])->assertCreated()->assertJsonPath('totals.held','500.00');
  $this->actingAs($owner)->getJson('/api/leases/'.$lease.'/deposit')
   ->assertOk()->assertJsonPath('totals.received','2000.00')
   ->assertJsonPath('totals.refunded','1500.00')->assertJsonPath('totals.held','500.00');
  $this->actingAs($owner)->getJson('/api/reports/cashflow?from=2025-01-01&to=2026-12-31')
   ->assertOk()->assertJsonPath('totals.received','0.00');
 }
 public function test_cannot_receive_or_refund_more_than_allowed():void{
  [$owner,$lease]=$this->fixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>2001,'occurred_on'=>'2025-01-01'
  ])->assertUnprocessable()->assertJsonValidationErrors('amount');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-01'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'refund','amount'=>1001,'occurred_on'=>'2026-11-05'
  ])->assertUnprocessable()->assertJsonValidationErrors('amount');
  $this->assertDatabaseCount('deposit_movements',1);
 }
 public function test_deduction_requires_documented_reason():void{
  [$owner,$lease]=$this->fixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-01'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>300,'occurred_on'=>'2026-11-05','reason'=>'damages'
  ])->assertUnprocessable()->assertJsonValidationErrors('reason');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>300,'occurred_on'=>'2026-11-05','reason'=>'إصلاح التلفيات المسجلة في محضر التسليم'
  ])->assertCreated()->assertJsonPath('totals.held','700.00');
 }
 public function test_duplicate_deposit_request_does_not_duplicate_movements():void {
  [$owner,$lease]=$this->fixture();
  $url='/api/leases/'.$lease.'/deposit';
  $body=['kind'=>'receipt','amount'=>750,'occurred_on'=>'2025-02-01','reference'=>'TRANSFER-1'];
  $this->withHeader('Idempotency-Key','unique-deposit-001')->postJson($url,$body)->assertCreated();
  $this->withHeader('Idempotency-Key','unique-deposit-001')->postJson($url,$body)
   ->assertOk()->assertJsonPath('totals.held','750.00');
  $this->withHeader('Idempotency-Key','unique-deposit-001')->postJson($url,[
   ...$body,'amount'=>200
  ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
  $this->assertDatabaseCount('deposit_movements',1);
 }
 public function test_other_owner_cannot_read_or_change_deposit_ledger():void {
  [$owner,$lease]=$this->fixture();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->getJson('/api/leases/'.$lease.'/deposit')->assertNotFound();
  $this->actingAs($other)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>50,'occurred_on'=>'2025-01-01'
  ])->assertNotFound();
 }
 public function test_cannot_refund_before_lease_expiry():void {
  $owner=User::create(['name'=>'Owner','email'=>'newowner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2026-01-01',
   'ends_on'=>'2028-12-31','total_amount'=>12000,'deposit'=>1000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2026-11-05'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'refund','amount'=>1000,'occurred_on'=>'2026-11-05'
  ])->assertUnprocessable()->assertJsonValidationErrors('kind');
 }
}
