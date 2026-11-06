<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UnitHandoverTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'A101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  return [$owner,$lease];
 }
 public function test_check_in_and_check_out_are_unique_and_chronological():void {
  [$owner,$lease]=$this->fixture();
  $url='/api/leases/'.$lease.'/handovers';
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'check_out','handed_over_on'=>'2026-11-05','condition'=>'good'
  ])->assertUnprocessable()->assertJsonValidationErrors('kind');
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good',
   'keys_count'=>3,'electricity_meter'=>100,'water_meter'=>50
  ])->assertCreated()->assertJsonPath('keys_count',3);
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertUnprocessable()->assertJsonValidationErrors('kind');
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'check_out','handed_over_on'=>'2024-12-31','condition'=>'damaged'
  ])->assertUnprocessable()->assertJsonValidationErrors('handed_over_on');
  $this->actingAs($owner)->postJson($url,[
   'kind'=>'check_out','handed_over_on'=>'2026-11-05','condition'=>'needs_repair',
   'notes'=>'Damaged air conditioning documented'
  ])->assertCreated();
  $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(2,'handovers');
 }
 public function test_completed_lease_requires_exit_handover_if_check_in_exists():void {
  [$owner,$lease]=$this->fixture();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/payments',[
   'amount'=>12000,'paid_on'=>'2025-12-31','method'=>'bank_transfer'
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertCreated();
  $this->actingAs($owner)->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('handover.complete',false)
   ->assertJsonPath('ready_for_financial_closure',false);
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/complete')
   ->assertUnprocessable()->assertJsonValidationErrors('status');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_out','handed_over_on'=>'2026-11-05','condition'=>'good'
  ])->assertCreated();
  $this->actingAs($owner)->getJson('/api/leases/'.$lease.'/settlement')
   ->assertOk()->assertJsonPath('handover.complete',true)
   ->assertJsonPath('ready_for_financial_closure',true);
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/complete')
   ->assertOk()->assertJsonPath('status','completed');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertUnprocessable();
 }
 public function test_other_owner_cannot_view_or_create_handovers():void {
  [$owner,$lease]=$this->fixture();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $url='/api/leases/'.$lease.'/handovers';
  $this->actingAs($other)->getJson($url)->assertNotFound();
  $this->actingAs($other)->postJson($url,[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertNotFound();
 }
 public function test_handover_supports_private_evidence_upload():void {
  Storage::fake('local');
  [$owner,$lease]=$this->fixture();
  $handover=$this->actingAs($owner)->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01','condition'=>'good'
  ])->assertCreated()->json('id');
  $upload=$this->actingAs($owner)->postJson('/api/documents',[
   'entity_type'=>'unit_handover','entity_id'=>$handover,
   'file'=>UploadedFile::fake()->create('handover.pdf',25,'application/pdf')
  ])->assertCreated();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->getJson('/api/documents/'.$upload->json('id'))->assertNotFound();
  $this->actingAs($other)->postJson('/api/documents',[
   'entity_type'=>'unit_handover','entity_id'=>$handover,
   'file'=>UploadedFile::fake()->create('other.pdf',25,'application/pdf')
  ])->assertNotFound();
 }
}
