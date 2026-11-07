<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPreviewTest extends TestCase
{
 use RefreshDatabase;

 public function test_owner_can_preview_pdf_and_image_with_private_nosniff_headers(): void
 {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'preview-owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($owner);
  foreach ([
   UploadedFile::fake()->create('deed.pdf',20,'application/pdf'),
   UploadedFile::fake()->create('unit.png',20,'image/png'),
  ] as $file) {
   $id=$this->postJson('/api/documents',[
    'entity_type'=>'property','entity_id'=>$property->id,'file'=>$file,
   ])->assertCreated()->json('id');
   $response=$this->get('/api/documents/'.$id.'/preview')->assertOk()
    ->assertHeader('X-Content-Type-Options','nosniff')
    ;
   $cache=$response->headers->get('Cache-Control');
   foreach (['private','no-store','max-age=0'] as $directive) {
    $this->assertContains($directive,array_map('trim',explode(',',$cache)));
   }
   $this->assertStringContainsString('inline',$response->headers->get('Content-Disposition'));
  }
 }

 public function test_preview_rejects_other_owners_and_unsupported_office_documents(): void
 {
  Storage::fake('local');
  $owner=User::create(['name'=>'Owner','email'=>'preview-a@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'preview-b@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($owner);
  $pdf=$this->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('deed.pdf',20,'application/pdf'),
  ])->assertCreated()->json('id');
  $this->actingAs($other)->get('/api/documents/'.$pdf.'/preview')->assertNotFound();
  $this->actingAs($owner);
  $office=$this->postJson('/api/documents',[
   'entity_type'=>'property','entity_id'=>$property->id,
   'file'=>UploadedFile::fake()->create('lease.docx',20,'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  ])->assertCreated()->json('id');
  $this->get('/api/documents/'.$office.'/preview')->assertStatus(415);
  $this->get('/api/documents/999999/preview')->assertNotFound();
 }
}
