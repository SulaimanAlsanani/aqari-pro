<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DamageWaiverTest extends TestCase{
 use RefreshDatabase;
 public function test_unresolved_exit_damage_must_be_waived_before_completion():void{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$p->units()->create(['code'=>'A1','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  foreach([['check_in','2025-01-01','good'],['check_out','2026-11-05','damaged']] as $item)
   $this->postJson('/api/leases/'.$lease.'/handovers',[
    'kind'=>$item[0],'handed_over_on'=>$item[1],'condition'=>$item[2]
   ])->assertCreated();
  $damage=$this->postJson('/api/leases/'.$lease.'/damages',[
   'description'=>'Broken air conditioner documented on exit','estimated_cost'=>300
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'cash'
  ])->assertCreated();
  $this->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('damages.unresolved_count',1)
   ->assertJsonPath('ready_for_financial_closure',false);
  $this->postJson('/api/leases/'.$lease.'/complete')->assertUnprocessable();
  $this->postJson('/api/leases/'.$lease.'/damages/'.$damage.'/waive',[
   'reason'=>'Owner agreed to waive repair charge'
  ])->assertOk();
  $this->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('damages.unresolved_count',0)
   ->assertJsonPath('ready_for_financial_closure',true);
  $this->postJson('/api/leases/'.$lease.'/complete')->assertOk();
 }
}
