<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
class DamageDisputeEvidenceTest extends TestCase {
 use RefreshDatabase;
 public function test_dispute_documents_are_private_to_the_property_owner():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  foreach([['check_in','2025-01-01','good'],['check_out','2026-11-05','damaged']] as $h)
   $this->postJson('/api/leases/'.$lease.'/handovers',[
    'kind'=>$h[0],'handed_over_on'=>$h[1],'condition'=>$h[2]
   ])->assertCreated();
  $damage=$this->postJson('/api/leases/'.$lease.'/damages',[
   'description'=>'اعتراض على قيمة تلفيات وحدة التكييف','estimated_cost'=>300
  ])->assertCreated()->json('id');
  $dispute=$this->postJson('/api/leases/'.$lease.'/damages/'.$damage.'/dispute',[
   'objection'=>'المستأجر يطعن في نسبة هذه التلفيات إليه'
  ])->assertCreated()->json('id');
  $this->actingAs($other)->postJson('/api/documents',[
   'entity_type'=>'damage_dispute','entity_id'=>$dispute,
   'file'=>UploadedFile::fake()->create('proof.pdf',12,'application/pdf')
  ])->assertNotFound();
  $file=$this->actingAs($owner)->postJson('/api/documents',[
   'entity_type'=>'damage_dispute','entity_id'=>$dispute,
   'file'=>UploadedFile::fake()->create('proof.pdf',12,'application/pdf')
  ])->assertCreated();
  $this->actingAs($other)->getJson('/api/documents/'.$file->json('id'))->assertNotFound();
  $this->actingAs($owner)->get('/api/documents/'.$file->json('id'))->assertOk();
 }
}
