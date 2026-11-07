<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class BillingTest extends TestCase{
 use RefreshDatabase;
 public function test_installments_total_matches_lease_value():void{
  $user=User::create(['name'=>'Landlord','email'=>'landlord@example.test','password'=>'password']);
  $p=$user->properties()->create(['name'=>'Apartment building','city'=>'Riyadh','type'=>'residential']);
  $u=$p->units()->create(['code'=>'A-101','type'=>'apartment']);
  $t=$user->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $r=$this->actingAs($user)->postJson('/api/leases',[
   'unit_id'=>$u->id,'tenant_id'=>$t->id,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
   'total_amount'=>10000,'installments'=>12,'deposit'=>1000
  ])->assertCreated();
  $lease=\App\Models\Lease::findOrFail($r->json('id'));
  $this->assertCount(12,$lease->dues);
  $this->assertSame(1000000,(int)round($lease->dues->sum('amount')*100));
 }
 public function test_payment_must_not_exceed_lease_total():void{
  $user=User::create(['name'=>'Landlord','email'=>'landlord@example.test','password'=>'password']);
  $p=$user->properties()->create(['name'=>'Building','city'=>'Riyadh','type'=>'residential']);
  $u=$p->units()->create(['code'=>'A-101','type'=>'apartment']);
  $t=$user->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $r=$this->actingAs($user)->postJson('/api/leases',[
   'unit_id'=>$u->id,'tenant_id'=>$t->id,'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
   'total_amount'=>10000,'installments'=>12
  ])->assertCreated();
  $id=$r->json('id');
  $data=['amount'=>8000,'paid_on'=>'2026-02-01','method'=>'bank_transfer'];
  $this->actingAs($user)->postJson('/api/leases/'.$id.'/payments',$data)->assertCreated();
  $this->actingAs($user)->postJson('/api/leases/'.$id.'/payments',$data)->assertUnprocessable()->assertJsonValidationErrors('amount');
 }
}
