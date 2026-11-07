<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentScannerExitCodeTest extends TestCase
{
 use RefreshDatabase;

 private function tryUploadWithScannerExitCode(int $code): void
 {
  Storage::fake('local');
  $owner=User::create([
   'name'=>'Scanner Owner '.$code,
   'email'=>'scanner-exit-'.$code.'@example.test',
   'password'=>'password',
  ]);
  $property=$owner->properties()->create(['name'=>'Test','city'=>'Riyadh','type'=>'residential']);
  $binary=tempnam(sys_get_temp_dir(),'aqari-scanner-');
  $this->assertNotFalse($binary);
  try {
   file_put_contents($binary,"#!/bin/sh\nexit ".$code."\n");
   chmod($binary,0700);
   config()->set('document_security.scan_enabled',true);
   config()->set('document_security.clamscan_binary',$binary);
   $response=$this->actingAs($owner)->postJson('/api/documents',[
    'entity_type'=>'property','entity_id'=>$property->id,
    'file'=>UploadedFile::fake()->create('evidence.pdf',10,'application/pdf'),
   ]);
   if ($code===1) {
    $response->assertUnprocessable()->assertJsonValidationErrors('file');
   } else {
    $response->assertStatus(503);
   }
   $this->assertDatabaseCount('documents',0);
   $this->assertSame([],Storage::disk('local')->allFiles('owners/'.$owner->id.'/documents'));
  } finally {
   @unlink($binary);
  }
 }

 public function test_infected_file_is_rejected_before_persistence(): void
 {
  $this->tryUploadWithScannerExitCode(1);
 }

 public function test_scanner_error_fails_closed_before_persistence(): void
 {
  $this->tryUploadWithScannerExitCode(2);
 }
}
