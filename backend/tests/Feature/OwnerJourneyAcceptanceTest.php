<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Owner V1 acceptance journey. Uses public API endpoints rather than direct writes
 * so permission, payment allocation and contract closure must all work together.
 */
class OwnerJourneyAcceptanceTest extends TestCase {
 use RefreshDatabase;

 public function test_owner_can_complete_documented_move_in_to_move_out_journey():void {
  Storage::fake('local');
  $owner=User::create(['name'=>'Landlord','email'=>'landlord@example.test','password'=>'password']);
  $other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'password']);
  $this->actingAs($owner);
  $property=$this->postJson('/api/properties',[
   'name'=>'Building A','city'=>'Riyadh','type'=>'residential'
  ])->assertCreated()->json('id');
  $unit=$this->postJson('/api/properties/'.$property.'/units',[
   'code'=>'A-01','type'=>'apartment'
  ])->assertCreated()->json('id');
  $tenant=$this->postJson('/api/tenants',[
   'name'=>'Resident','phone'=>'0500000000'
  ])->assertCreated()->json('id');
  $lease=$this->postJson('/api/leases',[
   'unit_id'=>$unit,'tenant_id'=>$tenant,
   'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31',
   'total_amount'=>12000,'deposit'=>2000,'installments'=>12
  ])->assertCreated()->json('id');
  $this->postJson('/api/leases/'.$lease.'/activate')->assertOk();

  $in=$this->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_in','handed_over_on'=>'2025-01-01',
   'condition'=>'good','keys_count'=>3,'electricity_meter'=>100,'water_meter'=>40
  ])->assertCreated()->json('id');
  $this->postJson('/api/documents',[
   'entity_type'=>'unit_handover','entity_id'=>$in,
   'file'=>UploadedFile::fake()->create('checkin.pdf',24,'application/pdf')
  ])->assertCreated();

  $this->withHeader('Idempotency-Key','owner-journey-payment-001')
   ->postJson('/api/leases/'.$lease.'/payments',[
    'amount'=>12000,'method'=>'bank_transfer','paid_on'=>'2025-12-31'
   ])->assertCreated();
  $this->withHeader('Idempotency-Key','owner-journey-payment-001')
   ->postJson('/api/leases/'.$lease.'/payments',[
    'amount'=>12000,'method'=>'bank_transfer','paid_on'=>'2025-12-31'
   ])->assertOk();
  $this->assertDatabaseCount('payments',1);

  $deposit='/api/leases/'.$lease.'/deposit';
  $this->postJson($deposit,['kind'=>'receipt','amount'=>2000,'occurred_on'=>'2025-01-01'])
   ->assertCreated();
  $this->postJson('/api/leases/'.$lease.'/handovers',[
   'kind'=>'check_out','handed_over_on'=>'2026-11-05',
   'condition'=>'damaged','keys_count'=>3,'electricity_meter'=>150,'water_meter'=>70
  ])->assertCreated();
  $damage=$this->postJson('/api/leases/'.$lease.'/damages',[
   'description'=>'Documented air conditioner damage in bedroom','estimated_cost'=>300
  ])->assertCreated()->json('id');
  $disputeUrl='/api/leases/'.$lease.'/damages/'.$damage.'/dispute';
  $dispute=$this->postJson($disputeUrl,[
   'objection'=>'Resident disputes the condition on the move-out report'
  ])->assertCreated()->json('id');

  $this->postJson($deposit,[
   'kind'=>'deduction','amount'=>250,'occurred_on'=>'2026-11-05',
   'reason'=>'Repair claim requires review','handover_damage_id'=>$damage
  ])->assertUnprocessable();
  $this->postJson('/api/leases/'.$lease.'/complete')->assertUnprocessable();
  $this->postJson($disputeUrl.'/resolve',[
   'resolution'=>'maintain_claim',
   'reason'=>'Photos and the initial move-in report support documented damage'
  ])->assertOk();
  $this->postJson($deposit,[
   'kind'=>'deduction','amount'=>250,'occurred_on'=>'2026-11-05',
   'reason'=>'Repair cost confirmed against move-out evidence',
   'handover_damage_id'=>$damage
  ])->assertCreated()->assertJsonPath('totals.held','1750.00');
  $this->postJson($deposit,[
   'kind'=>'refund','amount'=>1750,'occurred_on'=>'2026-11-05',
   'reason'=>'Remaining tenant deposit returned'
  ])->assertCreated()->assertJsonPath('totals.held','0.00');

  $this->getJson('/api/leases/'.$lease.'/settlement')->assertOk()
   ->assertJsonPath('rent.outstanding','0.00')
   ->assertJsonPath('deposit.deducted','250.00')
   ->assertJsonPath('deposit.refunded','1750.00')
   ->assertJsonPath('damages.unresolved_count',0)
   ->assertJsonPath('damages.open_disputes',0)
   ->assertJsonPath('ready_for_financial_closure',true);
  $this->getJson('/api/reconciliation')->assertOk()->assertJsonPath('data.0.balanced',true);
  $this->postJson('/api/leases/'.$lease.'/complete')->assertOk()->assertJsonPath('status','completed');

  $this->actingAs($other);
  $this->getJson('/api/leases/'.$lease.'/settlement')->assertNotFound();
  $this->getJson($disputeUrl)->assertNotFound();
  $this->getJson('/api/leases/'.$lease.'/handovers')->assertNotFound();
 }
}
