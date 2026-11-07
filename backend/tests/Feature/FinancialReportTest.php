<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class FinancialReportTest extends TestCase{
 use RefreshDatabase;
 public function test_cashflow_is_date_filtered_and_isolated_between_owners():void{
  $a=User::create(['name'=>'A','email'=>'a@example.test','password'=>'password']);
  $b=User::create(['name'=>'B','email'=>'b@example.test','password'=>'password']);
  $pa=$a->properties()->create(['name'=>'Owner A Property','city'=>'Riyadh','type'=>'residential']);
  $pb=$b->properties()->create(['name'=>'Owner B Property','city'=>'Jeddah','type'=>'residential']);
  $this->actingAs($a)->postJson('/api/properties/'.$pa->id.'/expenses',[
   'category'=>'maintenance','description'=>'Repair','amount'=>250,'incurred_on'=>'2026-08-05'
  ])->assertCreated();
  $this->actingAs($a)->postJson('/api/properties/'.$pa->id.'/expenses',[
   'category'=>'maintenance','description'=>'Other month','amount'=>500,'incurred_on'=>'2026-09-05'
  ])->assertCreated();
  $this->actingAs($b)->postJson('/api/properties/'.$pb->id.'/expenses',[
   'category'=>'maintenance','description'=>'Different owner','amount'=>999,'incurred_on'=>'2026-08-05'
  ])->assertCreated();
  $this->actingAs($a)->getJson('/api/reports/cashflow?from=2026-08-01&to=2026-08-31')
   ->assertOk()->assertJsonPath('totals.expenses','250.00')
   ->assertJsonPath('totals.received','0.00')
   ->assertJsonPath('properties.0.name','Owner A Property');
 }
 public function test_invalid_date_range_is_rejected():void{
  $user=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $this->actingAs($user)->getJson('/api/reports/cashflow?from=2026-12-31&to=2026-01-01')
   ->assertUnprocessable()->assertJsonValidationErrors('to');
 }
 public function test_guest_cannot_read_financial_reports():void{
  $this->getJson('/api/reports/cashflow?from=2026-01-01&to=2026-12-31')->assertUnauthorized();
 }
}
