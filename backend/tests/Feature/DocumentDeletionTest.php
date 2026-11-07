<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentDeletionTest extends TestCase {
 use RefreshDatabase;
 public function test_document_can_be_removed_only_by_its_owner():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $upload=$this->actingAs($owner)->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',22,'application/pdf')
  ])->assertCreated();
  $id=$upload->json('id');
  $document=\App\Models\Document::findOrFail($id);
  $path=$document->path;
  Storage::disk('local')->assertExists($path);
  $this->actingAs($other)->deleteJson('/api/documents/'.$id)->assertNotFound();
  Storage::disk('local')->assertExists($path);
  $this->actingAs($owner)->deleteJson('/api/documents/'.$id)->assertNoContent();
  $this->assertDatabaseMissing('documents',['id'=>$id]);
  Storage::disk('local')->assertMissing($path);
 }
}
