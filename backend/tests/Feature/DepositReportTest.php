<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositReportTest extends TestCase {
 use RefreshDatabase;

 private function createLease(User $owner,string $code):int {
  $property=$owner->properties()->create([
   'name'=>'Property '.$code,'city'=>'Riyadh','type'=>'residential'
  ]);
  $unit=$property->units()->create(['code'=>$code,'type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant '.$code,'phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'deposit'=>2000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  return (int)$lease;
 }
 public function test_deposits_are_liabilities_and_are_separated_from_rent_cashflow():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $lease=$this->createLease($owner,'A101');
  $url='/api/leases/'.$lease.'/deposit';
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'receipt','amount'=>1500,'occurred_on'=>'2025-01-10'
  ])->assertCreated();
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'refund','amount'=>400,'occurred_on'=>'2026-11-05'
  ])->assertCreated();
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'deduction','amount'=>100,'occurred_on'=>'2026-11-05',
   'reason'=>'إصلاح تلفيات موثقة بمحضر استلام'
  ])->assertCreated();
  $this->actingAs($owner)->getJson('/api/reports/deposits')->assertOk()
   ->assertJsonPath('accounting_basis','deposit_liability_not_rental_revenue')
   ->assertJsonPath('totals.received','1500.00')
   ->assertJsonPath('totals.refunded','400.00')
   ->assertJsonPath('totals.deducted','100.00')
   ->assertJsonPath('totals.held','1000.00')
   ->assertJsonPath('properties.0.held','1000.00');
  $this->actingAs($owner)->getJson('/api/reports/cashflow?from=2025-01-01&to=2026-12-31')
   ->assertOk()->assertJsonPath('totals.received','0.00');
 }
 public function test_another_owner_cannot_see_private_deposit_balances():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $lease=$this->createLease($owner,'A102');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-10'
  ])->assertCreated();
  $this->actingAs($other)->getJson('/api/reports/deposits')->assertOk()
   ->assertJsonPath('totals.held','0.00')->assertJsonCount(0,'properties');
 }
 public function test_guest_cannot_read_deposit_report():void {
  $this->getJson('/api/reports/deposits')->assertUnauthorized();
 }
}
