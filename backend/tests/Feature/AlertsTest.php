<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AlertsTest extends TestCase {
 use RefreshDatabase;
 public function test_alerts_include_unpaid_dues_but_not_paid_or_other_owners_dues():void{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'A','city'=>'Riyadh','type'=>'residential']);
  $unit=$p->units()->create(['code'=>'101','type'=>'apartment']);
  $t=$owner->tenants()->create(['name'=>'Renter','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$t->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->json('id').'/payments',[
   'amount'=>1000,'method'=>'cash','paid_on'=>'2025-02-01'
  ])->assertCreated();
  $alerts=$this->actingAs($owner)->getJson('/api/alerts')->assertOk()->json();
  $this->assertSame(11,$alerts['overdue_count']);
  $this->assertSame(0,$alerts['upcoming_count']);
  $this->actingAs($other)->getJson('/api/alerts')->assertOk()
   ->assertJsonPath('overdue_count',0)->assertJsonCount(0,'dues');
 }
 public function test_unauthenticated_access_is_denied():void{
  $this->getJson('/api/alerts')->assertUnauthorized();
 }
}
