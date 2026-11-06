<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TenantStatementTest extends TestCase {
 use RefreshDatabase;
 public function test_statement_shows_allocated_receipts_and_correct_outstanding():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'A Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  $payment=$this->actingAs($owner)->postJson('/api/leases/'.$lease->json('id').'/payments',[
   'amount'=>1500,'paid_on'=>'2026-02-01','method'=>'bank_transfer'
  ])->assertCreated();
  $this->actingAs($owner)->getJson('/api/tenants/'.$tenant->id.'/statement')
   ->assertOk()->assertJsonPath('totals.billed','12000.00')
   ->assertJsonPath('totals.received','1500.00')
   ->assertJsonPath('totals.outstanding','10500.00')
   ->assertJsonPath('dues.data.0.paid_amount','1000.00')
   ->assertJsonPath('dues.data.1.paid_amount','500.00');
  $this->actingAs($owner)->postJson('/api/payments/'.$payment->json('id').'/void',[
   'reason'=>'تم تسجيل عملية السداد بالخطأ'
  ])->assertOk();
  $this->actingAs($owner)->getJson('/api/tenants/'.$tenant->id.'/statement')
   ->assertJsonPath('totals.received','0.00')
   ->assertJsonPath('totals.outstanding','12000.00');
 }
 public function test_another_owner_cannot_read_private_statement():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $tenant=$owner->tenants()->create(['name'=>'A Tenant','phone'=>'0500000000']);
  $this->actingAs($other)->getJson('/api/tenants/'.$tenant->id.'/statement')->assertNotFound();
 }
}
