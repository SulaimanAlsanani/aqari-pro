<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
class LeaseValidationTest extends TestCase {
 use RefreshDatabase;
 private function user(string $email):User{return User::create(['name'=>'Owner','email'=>$email,'password'=>'password']);}
 public function test_cannot_lease_a_unit_owned_by_another_user():void {
  $owner=$this->user('one@example.test');$other=$this->user('two@example.test');
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$other->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $this->actingAs($other)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31','total_amount'=>24000,'installments'=>12
  ])->assertNotFound();
 }
 public function test_overlapping_leases_are_rejected():void {
  $owner=$this->user('one@example.test');
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $data=['unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31','total_amount'=>24000,'installments'=>12];
  $this->actingAs($owner)->postJson('/api/leases',$data)->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases',$data)->assertUnprocessable()->assertJsonValidationErrors('starts_on');
 }
}
