<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Expense};
use Illuminate\Foundation\Testing\RefreshDatabase;
class MaintenanceWorkflowTest extends TestCase {
 use RefreshDatabase;
 private function setupTicket():array {
  $user=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$user->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $ticket=$this->actingAs($user)->postJson('/api/properties/'.$property->id.'/tickets',[
   'title'=>'Air conditioning','priority'=>'normal','estimated_cost'=>200
  ])->assertCreated()->json();
  return [$user,$property,$ticket['id']];
 }
 public function test_resolution_creates_one_linked_expense_and_updates_cashflow():void {
  [$owner,$property,$id]=$this->setupTicket();
  $this->actingAs($owner)->postJson('/api/tickets/'.$id.'/resolve',[
   'actual_cost'=>275.50,'resolved_on'=>'2026-11-05','description'=>'Invoice from repair company'
  ])->assertOk()->assertJsonPath('ticket.status','resolved')
   ->assertJsonPath('expense.maintenance_ticket_id',$id);
  $this->assertDatabaseHas('expenses',['property_id'=>$property->id,'maintenance_ticket_id'=>$id,'amount'=>275.50]);
  $this->actingAs($owner)->getJson('/api/reports/cashflow?from=2026-11-01&to=2026-11-30')
   ->assertOk()->assertJsonPath('totals.expenses','275.50');
  $this->actingAs($owner)->postJson('/api/tickets/'.$id.'/resolve',[
   'actual_cost'=>275.50,'resolved_on'=>'2026-11-05'
  ])->assertUnprocessable()->assertJsonValidationErrors('status');
  $this->assertDatabaseCount('expenses',1);
 }
 public function test_zero_cost_resolve_does_not_create_expense():void {
  [$owner,$property,$id]=$this->setupTicket();
  $this->actingAs($owner)->postJson('/api/tickets/'.$id.'/resolve',[
   'actual_cost'=>0,'resolved_on'=>'2026-11-05'
  ])->assertOk()->assertJsonPath('expense',null);
  $this->assertDatabaseCount('expenses',0);
 }
 public function test_ticket_cannot_be_marked_resolved_without_cost_workflow():void {
  [$owner,$property,$id]=$this->setupTicket();
  $this->actingAs($owner)->patchJson('/api/tickets/'.$id,['status'=>'resolved'])
   ->assertUnprocessable()->assertJsonValidationErrors('status');
  $this->assertDatabaseHas('maintenance_tickets',['id'=>$id,'status'=>'open']);
  $this->assertDatabaseCount('expenses',0);
 }
 public function test_started_ticket_can_be_resolved_only_once():void {
  [$owner,$property,$id]=$this->setupTicket();
  $this->actingAs($owner)->patchJson('/api/tickets/'.$id,['status'=>'in_progress'])->assertOk();
  $this->actingAs($owner)->postJson('/api/tickets/'.$id.'/resolve',[
   'actual_cost'=>100,'resolved_on'=>'2026-11-05'
  ])->assertOk()->assertJsonPath('ticket.status','resolved');
  $this->actingAs($owner)->patchJson('/api/tickets/'.$id,['status'=>'in_progress'])->assertUnprocessable();
 }
 public function test_other_owner_cannot_resolve_ticket():void {
  [$owner,$property,$id]=$this->setupTicket();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->postJson('/api/tickets/'.$id.'/resolve',[
   'actual_cost'=>50,'resolved_on'=>'2026-11-05'
  ])->assertNotFound();
 }
}
