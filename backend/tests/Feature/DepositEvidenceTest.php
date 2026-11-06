<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DepositEvidenceTest extends TestCase {
 use RefreshDatabase;
 public function test_deposit_deduction_evidence_is_private_to_owner():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'deposit'=>1000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-02-01'
  ])->assertCreated();
  $movement=$this->actingAs($owner)->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>200,'occurred_on'=>'2026-11-05',
   'reason'=>'خصم إصلاح تلفيات موثقة بمحضر الاستلام'
  ])->assertCreated()->json('movement.id');

  $payload=['entity_type'=>'deposit_movement','entity_id'=>$movement,
   'file'=>UploadedFile::fake()->create('evidence.pdf',20,'application/pdf')];
  $this->actingAs($other)->postJson('/api/documents',$payload)->assertNotFound();
  $response=$this->actingAs($owner)->postJson('/api/documents',[
   'entity_type'=>'deposit_movement','entity_id'=>$movement,
   'file'=>UploadedFile::fake()->create('evidence.pdf',20,'application/pdf')
  ])->assertCreated();
  $documentId=$response->json('id');
  $this->actingAs($other)->getJson('/api/documents/'.$documentId)->assertNotFound();
  $this->actingAs($owner)->get('/api/documents/'.$documentId)->assertOk();
  $this->actingAs($other)->deleteJson('/api/documents/'.$documentId)->assertNotFound();
  $this->actingAs($owner)->deleteJson('/api/documents/'.$documentId)->assertNoContent();
 }
}
