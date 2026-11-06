<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
class OwnerIsolationTest extends TestCase {
 use RefreshDatabase;
 public function test_unauthenticated_user_cannot_read_portfolio():void {
  $this->getJson('/api/portfolio')->assertUnauthorized();
 }
 public function test_owner_cannot_read_another_owners_property():void {
  $owner=User::create(['name'=>'Owner A','email'=>'a@example.test','password'=>'password']);
  $other=User::create(['name'=>'Owner B','email'=>'b@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($other)->getJson('/api/properties/'.$property->id)->assertNotFound();
 }
 public function test_owner_cannot_create_unit_in_another_owners_property():void {
  $owner=User::create(['name'=>'Owner A','email'=>'a@example.test','password'=>'password']);
  $other=User::create(['name'=>'Owner B','email'=>'b@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($other)->postJson('/api/properties/'.$property->id.'/units',['code'=>'101','type'=>'apartment'])->assertNotFound();
 }
 public function test_owner_can_create_and_list_only_owned_property():void {
  $owner=User::create(['name'=>'Owner A','email'=>'a@example.test','password'=>'password']);
  $other=User::create(['name'=>'Owner B','email'=>'b@example.test','password'=>'password']);
  $other->properties()->create(['name'=>'Other Building','city'=>'Jeddah','type'=>'residential']);
  $this->actingAs($owner)->postJson('/api/properties',['name'=>'My Building','city'=>'Riyadh','type'=>'residential'])->assertCreated();
  $this->actingAs($owner)->getJson('/api/portfolio')->assertOk()->assertJsonPath('properties.total',1);
 }
}
