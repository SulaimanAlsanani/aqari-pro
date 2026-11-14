<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Lease};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AlertsTest extends TestCase {
 use RefreshDatabase;
 public function test_alerts_include_unpaid_dues_but_not_paid_or_other_owners_dues():void{
  $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $p=$owner->properties()->create(['name'=>'A','city'=>'Riyadh','type'=>'residential']);
  $unit=$p->units()->create(['code'=>'101','type'=>'apartment']);
  $t=$owner->tenants()->create(['name'=>'Renter','phone'=>'0500000000']);
  $lease=$this->actingAs($owner)->postJson('/api/leases',[
   'unit_id'=>$unit->id,'tenant_id'=>$t->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2025-12-31','total_amount'=>12000,'installments'=>12
  ])->assertCreated();
  $this->actingAs($owner)->postJson('/api/leases/'.$lease->json('id').'/payments',[
   'amount'=>1000,'method'=>'cash','paid_on'=>'2025-02-01'
  ])->assertCreated();
  $alerts=$this->actingAs($owner)->getJson('/api/alerts')->assertOk()->json();
  $this->assertSame(11,$alerts['overdue_count']);
  $this->assertSame(0,$alerts['upcoming_count']);
  $this->actingAs($other)->getJson('/api/alerts')->assertOk()
   ->assertJsonPath('overdue_count',0)->assertJsonCount(0,'dues');
 }
 private function syntheticLease(User $owner):Lease{
  $property=$owner->properties()->create([
   'name'=>'Alerts Tower','city'=>'Riyadh','type'=>'residential'
  ]);
  $unit=$property->units()->create(['code'=>'A-101','type'=>'apartment']);
  $tenant=$owner->tenants()->create(['name'=>'Resident','phone'=>'0500000000']);
  return $unit->leases()->create([
   'tenant_id'=>$tenant->id,'starts_on'=>'2025-01-01',
   'ends_on'=>'2027-12-31','total_amount'=>'1000.00',
   'deposit'=>'0.00','status'=>'active'
  ]);
 }

 public function test_paid_first_five_hundred_dues_cannot_hide_later_unpaid_alert():void{
  $this->travelTo(Carbon::parse('2027-06-15'));
  $owner=User::create([
   'name'=>'Large Ledger','email'=>'alerts-large@example.test','password'=>'password'
  ]);
  $lease=$this->syntheticLease($owner);
  $rows=[];
  for($i=1;$i<=510;$i++)$rows[]=[
   'lease_id'=>$lease->id,'sequence'=>$i,'due_on'=>'2025-01-01',
   'amount'=>'1.00','created_at'=>now(),'updated_at'=>now()
  ];
  foreach(array_chunk($rows,100) as $chunk)DB::table('dues')->insert($chunk);
  $payment=$lease->payments()->create([
   'amount'=>'510.00','paid_on'=>'2027-06-01','method'=>'cash'
  ]);
  $allocations=[];
  foreach($lease->dues()->orderBy('sequence')->pluck('id') as $dueId)$allocations[]=[
   'payment_id'=>$payment->id,'due_id'=>$dueId,'amount'=>'1.00',
   'created_at'=>now(),'updated_at'=>now()
  ];
  foreach(array_chunk($allocations,100) as $chunk)
   DB::table('payment_allocations')->insert($chunk);
  $unpaid=$lease->dues()->create([
   'sequence'=>511,'due_on'=>'2025-01-01','amount'=>'55.00'
  ]);

  $this->actingAs($owner,'sanctum')
   ->getJson('/api/alerts')->assertOk()
   ->assertJsonPath('overdue_count',1)
   ->assertJsonPath('upcoming_count',0)
   ->assertJsonPath('dues_total',1)
   ->assertJsonPath('has_more_dues',false)
   ->assertJsonCount(1,'dues')
   ->assertJsonPath('dues.0.due_id',$unpaid->id)
   ->assertJsonPath('dues.0.balance','55.00');
 }

 public function test_alert_preview_is_bounded_but_totals_include_all_unpaid_dues():void{
  $this->travelTo(Carbon::parse('2027-06-15'));
  $owner=User::create([
   'name'=>'Many Alerts','email'=>'alerts-many@example.test','password'=>'password'
  ]);
  $lease=$this->syntheticLease($owner);
  $rows=[];
  for($i=1;$i<=205;$i++)$rows[]=[
   'lease_id'=>$lease->id,'sequence'=>$i,'due_on'=>'2027-01-01',
   'amount'=>'1.00','created_at'=>now(),'updated_at'=>now()
  ];
  foreach(array_chunk($rows,100) as $chunk)DB::table('dues')->insert($chunk);
  $this->actingAs($owner,'sanctum')->getJson('/api/alerts')->assertOk()
   ->assertJsonPath('overdue_count',205)
   ->assertJsonPath('dues_total',205)
   ->assertJsonPath('has_more_dues',true)
   ->assertJsonCount(200,'dues');
 }

 public function test_corrupt_overallocated_due_causes_alerts_to_fail_closed():void{
  $owner=User::create([
   'name'=>'Alert Integrity','email'=>'alerts-corrupt@example.test','password'=>'password'
  ]);
  $lease=$this->syntheticLease($owner);
  $due=$lease->dues()->create([
   'sequence'=>1,'due_on'=>'2025-01-01','amount'=>'10.00'
  ]);
  $payment=$lease->payments()->create([
   'amount'=>'11.00','paid_on'=>'2026-01-01','method'=>'cash'
  ]);
  $payment->allocations()->create(['due_id'=>$due->id,'amount'=>'11.00']);
  $this->actingAs($owner,'sanctum')->getJson('/api/alerts')->assertStatus(409);
 }

 public function test_unauthenticated_access_is_denied():void{
  $this->getJson('/api/alerts')->assertUnauthorized();
 }
}
