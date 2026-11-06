<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class ExpenseUpdateTest extends TestCase {
 use RefreshDatabase;
 public function test_owner_can_correct_unlinked_expense_but_other_owner_cannot():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $expense=$this->actingAs($owner)->postJson('/api/properties/'.$p->id.'/expenses',[
   'category'=>'cleaning','description'=>'Cleaning','amount'=>100,'incurred_on'=>'2026-11-05'
  ])->assertCreated()->json('id');
  $this->actingAs($other)->patchJson('/api/expenses/'.$expense,['amount'=>900])->assertNotFound();
  $this->actingAs($owner)->patchJson('/api/expenses/'.$expense,['amount'=>120.25])
   ->assertOk()->assertJsonPath('amount','120.25');
 }
 public function test_closed_maintenance_expense_is_locked_against_direct_edit():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $ticket=$this->actingAs($owner)->postJson('/api/properties/'.$p->id.'/tickets',[
   'title'=>'Pipe repair','priority'=>'normal'
  ])->assertCreated()->json('id');
  $expense=$this->actingAs($owner)->postJson('/api/tickets/'.$ticket.'/resolve',[
   'actual_cost'=>100,'resolved_on'=>'2026-11-05'
  ])->assertOk()->json('expense.id');
  $this->actingAs($owner)->patchJson('/api/expenses/'.$expense,['amount'=>200])
   ->assertUnprocessable();
 }
}
