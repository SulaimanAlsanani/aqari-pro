<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class OwnerRecordsTest extends TestCase {
 use RefreshDatabase;
 public function test_property_records_are_not_leaked_through_collection_endpoints():void {
  $owner=User::create(['name'=>'First','email'=>'first@example.test','password'=>'password']);
  $other=User::create(['name'=>'Second','email'=>'second@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'Secret Building','city'=>'Riyadh','type'=>'residential']);
  $unit=$p->units()->create(['code'=>'SECRET-101','type'=>'apartment']);
  $owner->tenants()->create(['name'=>'Private Tenant','phone'=>'0500000000']);
  $this->actingAs($other)->getJson('/api/units')->assertOk()->assertJsonCount(0,'data');
  $this->actingAs($other)->getJson('/api/tenants')->assertOk()->assertJsonCount(0,'data');
  $this->actingAs($other)->getJson('/api/leases')->assertOk()->assertJsonCount(0,'data');
  $this->actingAs($other)->getJson('/api/expenses')->assertOk()->assertJsonCount(0,'data');
  $this->actingAs($other)->getJson('/api/tickets')->assertOk()->assertJsonCount(0,'data');
  $this->actingAs($other)->getJson('/api/documents')->assertOk()->assertJsonCount(0,'data');
 }
}
