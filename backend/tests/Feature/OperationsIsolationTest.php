<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
class OperationsIsolationTest extends TestCase {
 use RefreshDatabase;
 public function test_expense_cannot_be_attached_to_another_owners_property():void{
  $owner=User::create(['name'=>'A','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'B','email'=>'other@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($other)->postJson('/api/properties/'.$property->id.'/expenses',[
   'category'=>'maintenance','description'=>'Repair','amount'=>200,'incurred_on'=>'2026-11-06'
  ])->assertNotFound();
 }
 public function test_ticket_rejects_unit_from_different_property():void{
  $owner=User::create(['name'=>'A','email'=>'owner@example.test','password'=>'password']);
  $a=$owner->properties()->create(['name'=>'A','city'=>'Riyadh','type'=>'residential']);
  $b=$owner->properties()->create(['name'=>'B','city'=>'Riyadh','type'=>'residential']);
  $unit=$b->units()->create(['code'=>'101','type'=>'apartment']);
  $this->actingAs($owner)->postJson('/api/properties/'.$a->id.'/tickets',[
   'unit_id'=>$unit->id,'title'=>'Leaking pipe','priority'=>'high'
  ])->assertUnprocessable();
 }
}
