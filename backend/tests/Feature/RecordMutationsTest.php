<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
class RecordMutationsTest extends TestCase{
 use RefreshDatabase;
 public function test_owner_can_edit_own_property():void{
  $u=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $p=$u->properties()->create(['name'=>'Old','city'=>'Riyadh','type'=>'residential']);
  $this->actingAs($u)->patchJson('/api/properties/'.$p->id,['name'=>'Updated'])->assertOk()->assertJsonPath('name','Updated');
 }
 public function test_owner_cannot_modify_someone_elses_tenant():void{
  $a=User::create(['name'=>'A','email'=>'a@example.test','password'=>'password']);
  $b=User::create(['name'=>'B','email'=>'b@example.test','password'=>'password']);
  $t=$a->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $this->actingAs($b)->patchJson('/api/tenants/'.$t->id,['name'=>'Not yours'])->assertNotFound();
 }
 public function test_unit_code_must_be_unique_within_property():void{
  $u=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $p=$u->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $p->units()->create(['code'=>'A1','type'=>'apartment']);
  $second=$p->units()->create(['code'=>'A2','type'=>'apartment']);
  $this->actingAs($u)->patchJson('/api/units/'.$second->id,['code'=>'A1'])->assertUnprocessable()->assertJsonValidationErrors('code');
 }
}
