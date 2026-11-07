<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class CsvExportTest extends TestCase {
 use RefreshDatabase;
 public function test_csv_export_is_owner_scoped_and_escapes_spreadsheet_formulas():void{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $owner->properties()->create(['name'=>'=HYPERLINK("https://unsafe.invalid")','city'=>'Riyadh','type'=>'residential']);
  $other->properties()->create(['name'=>'Other Owner Private Building','city'=>'Jeddah','type'=>'residential']);
  $response=$this->actingAs($owner)->get('/api/exports/properties')->assertOk();
  $csv=$response->streamedContent();
  $this->assertStringContainsString("'=HYPERLINK",$csv);
  $this->assertStringNotContainsString('Other Owner Private Building',$csv);
  $this->assertStringContainsString('Riyadh',$csv);
 }
 public function test_export_rejects_anonymous_and_unknown_resources():void{
  $this->getJson('/api/exports/tenants')->assertUnauthorized();
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $this->actingAs($owner)->getJson('/api/exports/users')->assertNotFound();
 }
}
