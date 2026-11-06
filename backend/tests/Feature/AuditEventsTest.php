<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{User,AuditEvent};
class AuditEventsTest extends TestCase {
 use RefreshDatabase;
 public function test_user_can_see_only_their_own_audit_events():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($owner)->postJson('/api/properties',[
   'name'=>'My Building','city'=>'Riyadh','type'=>'residential'
  ])->assertCreated();
  $this->assertDatabaseHas('audit_events',['actor_id'=>$owner->id,'entity_type'=>'Property','action'=>'created']);
  $this->actingAs($other)->getJson('/api/audit')->assertOk()->assertJsonPath('total',0);
  $this->actingAs($owner)->getJson('/api/audit')->assertOk()->assertJsonPath('total',1);
 }
 public function test_audit_event_does_not_copy_sensitive_record_values():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $tenant=$this->actingAs($owner)->postJson('/api/tenants',[
   'name'=>'Private Person','phone'=>'0501234567'
  ])->assertCreated();
  $data=$this->actingAs($owner)->getJson('/api/audit')->assertOk()->json('data');
  $this->assertSame('Tenant',$data[0]['entity_type']);
  $this->assertStringNotContainsString('0501234567',json_encode($data));
 }
}
