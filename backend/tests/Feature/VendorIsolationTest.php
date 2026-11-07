<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class VendorIsolationTest extends TestCase {
 use RefreshDatabase;
 public function test_only_owner_can_list_edit_and_assign_vendors():void {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $vendor=$this->actingAs($owner)->postJson('/api/vendors',[
   'name'=>'Maintenance Company','service'=>'plumbing','phone'=>'0500000000'
  ])->assertCreated()->json('id');
  $this->actingAs($other)->getJson('/api/vendors')->assertOk()->assertJsonPath('total',0);
  $this->actingAs($other)->patchJson('/api/vendors/'.$vendor,['name'=>'Unauthorized'])->assertNotFound();
  $this->actingAs($other)->postJson('/api/properties/'.$p->id.'/tickets',[
   'title'=>'Pipe','priority'=>'normal','vendor_id'=>$vendor
  ])->assertNotFound();
  $this->actingAs($owner)->postJson('/api/properties/'.$p->id.'/tickets',[
   'title'=>'Pipe','priority'=>'normal','vendor_id'=>$vendor
  ])->assertCreated()->assertJsonPath('vendor_id',$vendor);
 }
 public function test_vendor_from_another_owner_cannot_be_assigned():void {
  $a=User::create(['name'=>'A','email'=>'a@example.test','password'=>'password']);
  $b=User::create(['name'=>'B','email'=>'b@example.test','password'=>'password']);
  $p=$a->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $v=$b->vendors()->create(['name'=>'External','service'=>'electricity']);
  $this->actingAs($a)->postJson('/api/properties/'.$p->id.'/tickets',[
   'title'=>'Cable','priority'=>'normal','vendor_id'=>$v->id
  ])->assertUnprocessable();
 }
}
