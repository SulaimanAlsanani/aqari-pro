<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DamageDisputeTest extends TestCase {
 use RefreshDatabase;
 private function fixture(string $email='owner@example.test'):array {
  $owner=User::create(['name'=>'Owner','email'=>$email,'password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'deposit'=>1000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/activate')->assertOk();
  foreach([['check_in','2025-01-01','good'],['check_out','2026-11-05','damaged']] as $h)
   $this->postJson('/api/leases/'.$lease.'/handovers',[
    'kind'=>$h[0],'handed_over_on'=>$h[1],'condition'=>$h[2]
   ])->assertCreated();
  $damage=$this->postJson('/api/leases/'.$lease.'/damages',[
   'description'=>'تلفيات موثقة في وحدة التكييف عند الخروج','estimated_cost'=>300
  ])->assertCreated()->json('id');
  return [$owner,$lease,$damage];
 }
 public function test_objection_blocks_deduction_and_waiver_until_review():void {
  [$owner,$lease,$damage]=$this->fixture();
  $url='/api/leases/'.$lease.'/damages/'.$damage.'/dispute';
  $this->postJson($url,['objection'=>'المستأجر يعترض على نسبة التلف إليه'])->assertCreated();
  $this->postJson($url,['objection'=>'محاولة تسجيل اعتراض ثاني على التلف'])->assertUnprocessable();
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-01'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>100,'occurred_on'=>'2026-11-05',
   'reason'=>'خصم إصلاح تلفيات بالمحضر','handover_damage_id'=>$damage
  ])->assertUnprocessable()->assertJsonValidationErrors('handover_damage_id');
  $this->postJson('/api/leases/'.$lease.'/damages/'.$damage.'/waive',[
   'reason'=>'الإعفاء قبل الفصل في الاعتراض'
  ])->assertUnprocessable();
  $this->getJson('/api/leases/'.$lease.'/settlement')->assertOk()
   ->assertJsonPath('damages.open_disputes',1)
   ->assertJsonPath('ready_for_financial_closure',false);
  $this->postJson($url.'/resolve',[
   'resolution'=>'waive_claim',
   'reason'=>'بعد مراجعة المحضر تقرر إعفاء المستأجر من التكلفة'
  ])->assertOk()->assertJsonPath('status','resolved');
  $this->getJson('/api/leases/'.$lease.'/settlement')->assertOk()
   ->assertJsonPath('damages.open_disputes',0);
  $this->postJson($url.'/resolve',[
   'resolution'=>'maintain_claim','reason'=>'محاولة تغيير القرار بعد إغلاق الاعتراض'
  ])->assertUnprocessable();
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>100,'occurred_on'=>'2026-11-05',
   'reason'=>'محاولة الخصم بعد إعفاء المستأجر','handover_damage_id'=>$damage
  ])->assertUnprocessable();
 }
 public function test_maintained_claim_can_be_deducted_after_resolution():void {
  [$owner,$lease,$damage]=$this->fixture();
  $url='/api/leases/'.$lease.'/damages/'.$damage.'/dispute';
  $this->postJson($url,['objection'=>'العميل لا يوافق على قيمة التصليح المذكورة'])->assertCreated();
  $this->postJson($url.'/resolve',[
   'resolution'=>'maintain_claim',
   'reason'=>'فحص الصور والفاتورة يثبت التكلفة المذكورة'
  ])->assertOk();
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'receipt','amount'=>1000,'occurred_on'=>'2025-01-01'
  ])->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/deposit',[
   'kind'=>'deduction','amount'=>200,'occurred_on'=>'2026-11-05',
   'reason'=>'خصم إصلاح التلف بعد مراجعة الاعتراض','handover_damage_id'=>$damage
  ])->assertCreated();
 }
 public function test_other_owner_cannot_read_or_resolve_dispute():void {
  [$owner,$lease,$damage]=$this->fixture();
  $url='/api/leases/'.$lease.'/damages/'.$damage.'/dispute';
  $this->postJson($url,['objection'=>'خلاف على تكلفة أعمال الإصلاح الموثقة'])->assertCreated();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->getJson($url)->assertNotFound();
  $this->actingAs($other)->postJson($url.'/resolve',[
   'resolution'=>'waive_claim','reason'=>'محاولة غير مصرح بها لإعفاء التلفيات'
  ])->assertNotFound();
 }
}
