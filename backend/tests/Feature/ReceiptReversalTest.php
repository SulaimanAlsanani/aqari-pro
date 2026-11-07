<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Lease,Payment};
use Illuminate\Foundation\Testing\RefreshDatabase;
class ReceiptReversalTest extends TestCase {
 use RefreshDatabase;
 private function lease():array {
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $u=$p->units()->create(['code'=>'101','type'=>'apartment']);
  $t=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $response=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$u->id,'tenant_id'=>$t->id,'starts_on'=>'2026-01-01',
   'ends_on'=>'2026-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  return [$owner,Lease::findOrFail($response->json('id'))];
 }
 public function test_reversing_receipt_restores_installment_balance_and_preserves_history():void {
  [$owner,$lease]=$this->lease();
  $payment=$this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>1000,'paid_on'=>'2026-02-01','method'=>'bank_transfer'
  ])->assertCreated();
  $id=$payment->json('id');
  $this->actingAs($owner)->postJson('/api/payments/'.$id.'/void',[
   'reason'=>'تم تسجيل الحوالة على العقد بطريق الخطأ'
  ])->assertOk();
  $this->assertDatabaseHas('payments',['id'=>$id,'void_reason'=>'تم تسجيل الحوالة على العقد بطريق الخطأ']);
  $this->assertDatabaseCount('payment_allocations',1);
  $dues=$this->actingAs($owner)->getJson('/api/dues')->assertOk()->json('data');
  $this->assertSame('0.00',$dues[0]['paid_amount']);
  $this->assertSame('1000.00',$dues[0]['balance_amount']);
  $this->actingAs($owner)->getJson('/api/reports/cashflow?from=2026-01-01&to=2026-12-31')
   ->assertOk()->assertJsonPath('totals.received','0.00');
 }
 public function test_receipt_cannot_be_reversed_twice():void {
  [$owner,$lease]=$this->lease();
  $p=$this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>100,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertCreated();
  $url='/api/payments/'.$p->json('id').'/void';
  $this->actingAs($owner)->postJson($url,['reason'=>'خطأ في تسجيل سند القبض'])->assertOk();
  $this->actingAs($owner)->postJson($url,['reason'=>'إلغاء السند للمرة الثانية'])->assertUnprocessable();
 }
 public function test_another_owner_cannot_reverse_receipt():void {
  [$owner,$lease]=$this->lease();
  $p=$this->actingAs($owner)->postJson('/api/leases/'.$lease->id.'/payments',[
   'amount'=>100,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertCreated();
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($other)->postJson('/api/payments/'.$p->json('id').'/void',[
   'reason'=>'محاولة إلغاء سند من مالك آخر'
  ])->assertNotFound();
 }
}
