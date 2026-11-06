<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
class DocumentSecurityTest extends TestCase {
 use RefreshDatabase;
 public function test_other_owner_cannot_attach_document_to_my_property():void {
  Storage::fake('local');
  $a=User::create(['name'=>'A','email'=>'a@example.test','password'=>'password']);
  $b=User::create(['name'=>'B','email'=>'b@example.test','password'=>'password']);
  $property=$a->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($b)->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('lease.pdf',32,'application/pdf')
  ])->assertNotFound();
 }
 public function test_owner_can_upload_but_other_owner_cannot_download():void {
  Storage::fake('local');
  $a=User::create(['name'=>'A','email'=>'a@example.test','password'=>'password']);
  $b=User::create(['name'=>'B','email'=>'b@example.test','password'=>'password']);
  $property=$a->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $resp=$this->actingAs($a)->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('lease.pdf',32,'application/pdf')
  ])->assertCreated();
  $id=$resp->json('id');
  $this->actingAs($b)->get('/api/documents/'.$id)->assertNotFound();
 }
}
