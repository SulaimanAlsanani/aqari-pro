<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositExportTest extends TestCase {
 use RefreshDatabase;

 public function test_csv_export_contains_only_owners_deposit_movements():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  foreach([[$owner,'A101'],[$other,'B202']] as [$person,$code]) {
   $property=$person->properties()->create(['name'=>'Property '.$code,'city'=>'Riyadh','type'=>'residential']);
   $unit=$property->units()->create(['code'=>$code,'type'=>'apartment']);
   $tenant=$person->tenants()->create(['name'=>'Tenant '.$code,'phone'=>'0500000000']);
   $lease=$this->actingAs($person)->postJson('/api/leases',[
    'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
    'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
    'total_amount'=>12000,'deposit'=>2000,'installments'=>12
   ])->assertCreated()->json('id');
   $this->actingAs($person)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
   $this->actingAs($person)->postJson('/api/leases/'.$lease.'/deposit',[
    'kind'=>'receipt','amount'=>500,'occurred_on'=>'2025-02-01',
    'reference'=>$person->id===$owner->id?'OWNER-RECEIPT':'OTHER-RECEIPT'
   ])->assertCreated();
  }
  $response=$this->actingAs($owner)->get('/api/exports/deposit_movements')->assertOk();
  $csv=$response->streamedContent();
  $this->assertStringContainsString('OWNER-RECEIPT',$csv);
  $this->assertStringNotContainsString('OTHER-RECEIPT',$csv);
 }

 public function test_guests_cannot_export_deposit_movements():void {
  $this->getJson('/api/exports/deposit_movements')->assertUnauthorized();
 }
}
