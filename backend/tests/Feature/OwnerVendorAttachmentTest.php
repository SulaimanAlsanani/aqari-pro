<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class OwnerVendorAttachmentTest extends TestCase {
 use RefreshDatabase;
 public function test_owner_can_attach_document_to_their_profile():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $this->actingAs($owner)->postJson('/api/documents',[
   'entity_type'=>'owner','entity_id'=>$owner->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',18,'application/pdf')
  ])->assertCreated()->assertJsonPath('entity_type','owner');
 }
 public function test_owner_cannot_attach_to_another_owners_profile():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->postJson('/api/documents',[
   'entity_type'=>'owner','entity_id'=>$owner->id,
   'file'=>UploadedFile::fake()->create('doc.pdf',18,'application/pdf')
  ])->assertNotFound();
 }
 public function test_vendor_attachment_cannot_cross_owner_boundary():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $vendor=$owner->vendors()->create(['name'=>'Vendor','service'=>'plumbing']);
  $this->actingAs($other)->postJson('/api/documents',[
   'entity_type'=>'vendor','entity_id'=>$vendor->id,
   'file'=>UploadedFile::fake()->create('quote.pdf',18,'application/pdf')
  ])->assertNotFound();
  $this->actingAs($owner)->postJson('/api/documents',[
   'entity_type'=>'vendor','entity_id'=>$vendor->id,
   'file'=>UploadedFile::fake()->create('quote.pdf',18,'application/pdf')
  ])->assertCreated();
 }
}
