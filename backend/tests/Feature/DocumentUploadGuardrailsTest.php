<?php
namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadGuardrailsTest extends TestCase
{
 use RefreshDatabase;

 private function ownerWithProperty(): array
 {
  $owner=User::create(['name'=>'Owner','email'=>'guard-owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'A','city'=>'Riyadh','type'=>'residential']);
  return [$owner,$property];
 }

 public function test_rejects_disallowed_extension_and_oversized_file_without_persisting_documents(): void
 {
  Storage::fake('local');
  [$owner,$property]=$this->ownerWithProperty();
  $this->actingAs($owner);

  foreach ([
   UploadedFile::fake()->create('dangerous.exe',12,'application/x-msdownload'),
   UploadedFile::fake()->create('oversized.pdf',10241,'application/pdf'),
  ] as $file) {
   $this->postJson('/api/documents',[
    'entity_type'=>'property','entity_id'=>$property->id,'file'=>$file,
   ])->assertUnprocessable()->assertJsonValidationErrors('file');
  }

  $this->assertDatabaseCount('documents',0);
  $this->assertCount(0,Storage::disk('local')->allFiles('owners/'.$owner->id.'/documents'));
 }

 public function test_rejects_invalid_entity_and_cross_owner_upload_with_no_orphaned_file(): void
 {
  Storage::fake('local');
  [$owner,$property]=$this->ownerWithProperty();
  $other=User::create(['name'=>'Other','email'=>'guard-other@example.test','password'=>'password']);
  $this->actingAs($other);
  $this->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',10,'application/pdf'),
  ])->assertNotFound();
  $this->postJson('/api/documents',[
   'entity_type'=>'not_supported','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',10,'application/pdf'),
  ])->assertUnprocessable()->assertJsonValidationErrors('entity_type');

  $this->assertDatabaseCount('documents',0);
  $this->assertCount(0,Storage::disk('local')->allFiles('owners/'.$other->id.'/documents'));
 }

 public function test_another_owner_cannot_preview_download_or_delete_a_private_attachment(): void
 {
  Storage::fake('local');
  [$owner,$property]=$this->ownerWithProperty();
  $other=User::create(['name'=>'Other','email'=>'guard-other@example.test','password'=>'password']);
  $this->actingAs($owner);
  $id=$this->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',10,'application/pdf'),
  ])->assertCreated()->json('id');
  $path=Document::findOrFail($id)->path;
  $this->actingAs($other);
  $this->get('/api/documents/'.$id.'/preview')->assertNotFound();
  $this->get('/api/documents/'.$id)->assertNotFound();
  $this->deleteJson('/api/documents/'.$id)->assertNotFound();

  $this->assertDatabaseHas('documents',['id'=>$id,'user_id'=>$owner->id]);
  Storage::disk('local')->assertExists($path);
 }

 public function test_guest_cannot_upload_or_preview_documents(): void
 {
  Storage::fake('local');
  [$owner,$property]=$this->ownerWithProperty();
  $this->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',10,'application/pdf'),
  ])->assertUnauthorized();
  $this->getJson('/api/documents/1/preview')->assertUnauthorized();
  $this->assertDatabaseCount('documents',0);
 }
}
