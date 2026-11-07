<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
class ReceiptIdempotencyTest extends TestCase {
 use RefreshDatabase;
 private function setupLease():array{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $property=$owner->properties()->create(['name'=>'Tower','city'=>'Riyadh','type'=>'residential']);
  $unit=$property->units()->create(['code'=>'101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Tenant','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$tenant->id,
   'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
   'total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  return [$owner,$lease->json('id')];
 }
 public function test_same_key_and_payload_replays_receipt_without_double_charge():void{
  [$owner,$leaseId]=$this->setupLease();
  $body=['amount'=>750,'paid_on'=>'2026-02-01','method'=>'cash'];
  $url='/api/leases/'.$leaseId.'/payments';
  $this->withHeader('Idempotency-Key','receipt-unique-123')->postJson($url,$body)->assertCreated();
  $second=$this->withHeader('Idempotency-Key','receipt-unique-123')->postJson($url,$body);
  $second->assertOk()->assertJsonPath('amount','750.00');
  $this->assertDatabaseCount('payments',1);
  $this->assertEqualsWithDelta(750,(float)\App\Models\PaymentAllocation::sum('amount'),0.001);
 }
 public function test_same_key_with_different_amount_is_rejected():void{
  [$owner,$leaseId]=$this->setupLease();
  $url='/api/leases/'.$leaseId.'/payments';
  $this->withHeader('Idempotency-Key','receipt-unique-124')->postJson($url,[
   'amount'=>100,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertCreated();
  $this->withHeader('Idempotency-Key','receipt-unique-124')->postJson($url,[
   'amount'=>150,'paid_on'=>'2026-02-01','method'=>'cash'
  ])->assertUnprocessable();
  $this->assertDatabaseCount('payments',1);
 }
 public function test_key_replay_cannot_resurrect_voided_receipt():void{
  [$owner,$leaseId]=$this->setupLease();
  $url='/api/leases/'.$leaseId.'/payments';
  $body=['amount'=>100,'paid_on'=>'2026-02-01','method'=>'cash'];
  $receipt=$this->withHeader('Idempotency-Key','receipt-unique-125')->postJson($url,$body)->assertCreated();
  $this->postJson('/api/payments/'.$receipt->json('id').'/void',['reason'=>'سند قبض أدخل بطريق الخطأ'])->assertOk();
  $this->withHeader('Idempotency-Key','receipt-unique-125')->postJson($url,$body)
   ->assertUnprocessable();
  $this->assertDatabaseCount('payments',1);
 }
}
