<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class LeaseCloseDepositTest extends TestCase {
 use RefreshDatabase;
 public function test_completion_waits_for_held_deposit_to_be_resolved():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'A1','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $id=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'deposit'=>1000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$id.'/activate')->assertOk();
  $this->postJson('/api/leases/'.$id.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'cash'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$id.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-10'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$id.'/complete')->assertUnprocessable();
  $this->postJson('/api/leases/'.$id.'/deposit',[
   'kind'=>'refund','amount'=>1000,'occurred_on'=>'2026-11-05'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$id.'/complete')->assertOk()->assertJsonPath('status','completed');
  $this->postJson('/api/leases/'.$id.'/deposit',[
   'kind'=>'receipt','amount'=>1,'occurred_on'=>'2026-11-05'
  ])->assertUnprocessable()->assertJsonValidationErrors('kind');
  $this->postJson('/api/leases/'.$id.'/deposit',[
   'kind'=>'refund','amount'=>1,'occurred_on'=>'2026-11-05'
  ])->assertUnprocessable()->assertJsonValidationErrors('kind');
  $this->assertDatabaseCount('deposit_movements',2);
  $this->getJson('/api/leases/'.$id.'/settlement')->assertOk()
   ->assertJsonPath('deposit.held','0.00')
   ->assertJsonPath('ready_for_financial_closure',true);
 }
}
