<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverDamageTest extends TestCase {
 use RefreshDatabase;
 private function fixture(string $email):array {
  $owner=User::create(['name'=>'Owner','email'=>$email,'password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'A101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'deposit'=>1000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  return [$owner,$lease];
 }
 private function damage(int $lease):int {
  $this->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_out','handed_over_on'=>'2026-11-05','condition'=>'damaged'
  ])->assertCreated();
  return (int)$this->postJson('/api/leases/'.$lease.'/damages',[
   'description'=>'تلف في مكيف الغرفة مثبت بالصور','estimated_cost'=>300
  ])->assertCreated()->json('id');
 }
 public function test_documented_damage_can_be_deducted_only_once_within_estimate():void {
  [$owner,$lease]=$this->fixture('owner@example.test');
  $damage=$this->damage($lease);
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-02-01'
  ])->assertCreated();
  $endpoint='/api/leases/'.$lease.'/deposit';
  $body=['kind'=>'deduction','amount'=>200,'occurred_on'=>'2026-11-05',
    'reason'=>'تكلفة إصلاح التلف الموثق في محضر الخروج','handover_damage_id'=>$damage];
  $this->postJson($endpoint,$body)->assertCreated()->assertJsonPath('totals.held','800.00');
  $this->postJson($endpoint,$body)->assertUnprocessable()->assertJsonValidationErrors('handover_damage_id');
  $this->getJson('/api/leases/'.$lease.'/damages')->assertOk()->assertJsonPath('damages.0.deduction.amount','200.00');
 }
 public function test_damage_deduction_cannot_exceed_estimate_or_cross_lease():void {
  [$owner,$lease]=$this->fixture('owner@example.test');
  $damage=$this->damage($lease);
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-02-01'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>301,'occurred_on'=>'2026-11-05',
   'reason'=>'مطالبة خصم موثقة لمحضر الخروج','handover_damage_id'=>$damage
  ])->assertUnprocessable()->assertJsonValidationErrors('amount');
  [$other,$otherLease]=$this->fixture('other@example.test');
  $this->actingAs($other)->postJson('/api/leases/'.$otherLease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-02-01'
  ])->assertCreated();
  $this->actingAs($other)->postJson('/api/leases/'.$otherLease.'/deposit',[
   'kind'=>'deduction','amount'=>100,'occurred_on'=>'2026-11-05',
   'reason'=>'محاولة خصم من تلف تابع لعقد آخر','handover_damage_id'=>$damage
  ])->assertUnprocessable()->assertJsonValidationErrors('handover_damage_id');
  $this->actingAs($other)->getJson('/api/leases/'.$lease.'/damages')->assertNotFound();
 }
 public function test_damage_requires_exit_handover_and_non_good_condition():void {
  [$owner,$lease]=$this->fixture('owner@example.test');
  $payload=['description'=>'تلف موثق في أبواب الوحدة عند الخروج','estimated_cost'=>250];
  $this->postJson('/api/leases/'.$lease.'/damages',$payload)
   ->assertUnprocessable()->assertJsonValidationErrors('unit_handover_id');
  $this->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_out','handed_over_on'=>'2026-11-05','condition'=>'good'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/damages',$payload)
   ->assertUnprocessable()->assertJsonValidationErrors('condition');
 }
}
