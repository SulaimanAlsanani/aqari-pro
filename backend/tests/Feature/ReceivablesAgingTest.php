<?php
namespace Tests\Feature;

use App\Models\{Lease,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ReceivablesAgingTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email): User
    {
        return User::create([
            'name'=>'Aging owner',
            'email'=>$email,
            'password'=>'strong-password-for-testing'
        ]);
    }

    private function lease(User $owner, string $name='Tower', string $status='active'): Lease
    {
        $property=$owner->properties()->create([
            'name'=>$name,'city'=>'Riyadh','type'=>'residential'
        ]);
        $unit=$property->units()->create(['code'=>'A-101','type'=>'apartment']);
        $tenant=$owner->tenants()->create([
            'name'=>'Resident '.$name,'phone'=>'0500000000'
        ]);
        return $unit->leases()->create([
            'tenant_id'=>$tenant->id,'starts_on'=>'2027-01-01',
            'ends_on'=>'2027-12-31','total_amount'=>'900.00',
            'deposit'=>'0.00','status'=>$status
        ]);
    }

    public function test_partial_receipts_age_only_active_owners_unpaid_installments(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15 12:00:00'));
        $owner=$this->owner('aging-owner@example.test');
        $foreign=$this->owner('aging-foreign@example.test');
        $lease=$this->lease($owner,'Owner Tower');
        foreach (['2027-01-01','2027-04-01','2027-07-01'] as $i=>$day) {
            $lease->dues()->create([
                'sequence'=>$i+1,'due_on'=>$day,'amount'=>'300.00'
            ]);
        }
        $foreignLease=$this->lease($foreign,'Private Tower');
        $foreignLease->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'9999.00'
        ]);
        $draft=$this->lease($owner,'Draft Tower','draft');
        $draft->dues()->create(['sequence'=>1,'due_on'=>'2027-01-01','amount'=>'5000.00']);

        $this->actingAs($owner,'sanctum');
        $this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'150.00','paid_on'=>'2027-06-10','method'=>'cash'
        ])->assertCreated();

        $response=$this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('as_of','2027-06-15')
            ->assertJsonPath('totals.outstanding','750.00')
            ->assertJsonPath('totals.overdue','450.00')
            ->assertJsonPath('totals.not_overdue','300.00')
            ->assertJsonPath('totals.days_1_30','0.00')
            ->assertJsonPath('totals.days_31_60','0.00')
            ->assertJsonPath('totals.days_61_90','300.00')
            ->assertJsonPath('totals.days_over_90','150.00')
            ->assertJsonPath('counts.overdue_installments',2)
            ->assertJsonPath('counts.outstanding_installments',3)
            ->assertJsonPath('has_more_overdue',false)
            ->assertJsonPath('properties_with_balance',1)
            ->assertJsonPath('has_more_properties',false)
            ->assertJsonCount(1,'by_property')
            ->assertJsonPath('by_property.0.name','Owner Tower')
            ->assertJsonPath('by_property.0.outstanding','750.00')
            ->assertJsonPath('by_property.0.overdue','450.00')
            ->assertJsonPath('by_property.0.not_overdue','300.00')
            ->assertJsonPath('by_property.0.overdue_installments',2)
            ->assertJsonCount(2,'oldest_overdue');

        self::assertSame($lease->id,$response->json('oldest_overdue.0.lease_id'));
        self::assertSame('Owner Tower',$response->json('oldest_overdue.0.property'));
        self::assertSame('2027-01-01',$response->json('oldest_overdue.0.due_on'));
        self::assertSame('150.00',$response->json('oldest_overdue.0.outstanding'));
        self::assertNotContains('Private Tower',array_column(
            $response->json('oldest_overdue'),'property'
        ));
    }

    public function test_voiding_receipt_restores_unpaid_aging_balance(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15 12:00:00'));
        $owner=$this->owner('aging-reversal@example.test');
        $lease=$this->lease($owner);
        $lease->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'300.00'
        ]);
        $this->actingAs($owner,'sanctum');
        $payment=$this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'150.00','paid_on'=>'2027-06-10','method'=>'cash'
        ])->assertCreated()->json('id');
        $this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.overdue','150.00');
        $this->postJson('/api/payments/'.$payment.'/void',[
            'reason'=>'Duplicate receipt entered by test operator'
        ])->assertOk();
        $this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.overdue','300.00');
    }

    public function test_all_settled_dues_produce_zero_balances(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('aging-settled@example.test');
        $lease=$this->lease($owner);
        $lease->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'300.00'
        ]);
        $this->actingAs($owner,'sanctum');
        $this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'300.00','paid_on'=>'2027-06-10','method'=>'cash'
        ])->assertCreated();
        $this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.outstanding','0.00')
            ->assertJsonPath('totals.overdue','0.00')
            ->assertJsonCount(0,'oldest_overdue');
    }

    public function test_overdue_details_are_bounded_to_oldest_twenty(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('aging-many@example.test');
        $lease=$this->lease($owner);
        for ($i=0;$i<25;$i++) {
            $lease->dues()->create([
                'sequence'=>$i+1,
                'due_on'=>Carbon::parse('2027-01-01')->addDays($i)->toDateString(),
                'amount'=>'1.00'
            ]);
        }
        $this->actingAs($owner,'sanctum');
        $result=$this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.overdue','25.00')
            ->assertJsonPath('counts.overdue_installments',25)
            ->assertJsonPath('has_more_overdue',true)
            ->assertJsonCount(20,'oldest_overdue');
        self::assertSame('2027-01-01',$result->json('oldest_overdue.0.due_on'));
        self::assertSame('2027-01-20',$result->json('oldest_overdue.19.due_on'));
    }

    public function test_property_filter_limits_totals_and_rejects_foreign_property(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('aging-filter@example.test');
        $other=$this->owner('aging-filter-other@example.test');
        $one=$this->lease($owner,'Building One');
        $two=$this->lease($owner,'Building Two');
        $secret=$this->lease($other,'Private Building');
        $one->dues()->create(['sequence'=>1,'due_on'=>'2027-01-01','amount'=>'100.00']);
        $two->dues()->create(['sequence'=>1,'due_on'=>'2027-01-01','amount'=>'200.00']);
        $secret->dues()->create(['sequence'=>1,'due_on'=>'2027-01-01','amount'=>'999.00']);

        $oneId=$one->unit->property_id;
        $twoId=$two->unit->property_id;
        $foreignId=$secret->unit->property_id;
        $this->actingAs($owner,'sanctum');

        $this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.overdue','300.00');
        $this->getJson('/api/reports/receivables?property_id='.$oneId)->assertOk()
            ->assertJsonPath('property_id',$oneId)
            ->assertJsonPath('totals.overdue','100.00')
            ->assertJsonCount(1,'oldest_overdue');
        $this->getJson('/api/reports/receivables?property_id='.$twoId)->assertOk()
            ->assertJsonPath('totals.overdue','200.00');
        $this->getJson('/api/reports/receivables?property_id='.$foreignId)->assertNotFound();
        $this->getJson('/api/reports/receivables?property_id=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('property_id');
    }

    public function test_property_breakdown_ranks_largest_overdue_first_and_is_owner_scoped(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('aging-ranked-owner@example.test');
        $foreignOwner=$this->owner('aging-ranked-foreign@example.test');

        $small=$this->lease($owner,'Small Overdue');
        $large=$this->lease($owner,'Large Overdue');
        $future=$this->lease($owner,'Future Only');
        $foreign=$this->lease($foreignOwner,'Secret Large');

        $small->dues()->create([
            'sequence'=>1,'due_on'=>'2027-06-01','amount'=>'10.00'
        ]);
        $large->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'80.00'
        ]);
        $large->dues()->create([
            'sequence'=>2,'due_on'=>'2027-07-01','amount'=>'20.00'
        ]);
        $future->dues()->create([
            'sequence'=>1,'due_on'=>'2027-07-01','amount'=>'1000.00'
        ]);
        $foreign->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'9999.00'
        ]);

        $this->actingAs($owner,'sanctum');
        $response=$this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.outstanding','1110.00')
            ->assertJsonPath('totals.overdue','90.00')
            ->assertJsonPath('properties_with_balance',3)
            ->assertJsonPath('has_more_properties',false)
            ->assertJsonCount(3,'by_property');

        $rows=$response->json('by_property');
        self::assertSame(['Large Overdue','Small Overdue','Future Only'],array_column($rows,'name'));
        self::assertSame(['80.00','10.00','0.00'],array_column($rows,'overdue'));
        self::assertSame(['100.00','10.00','1000.00'],array_column($rows,'outstanding'));
        self::assertSame([1,1,0],array_column($rows,'overdue_installments'));

        $sum='0.00';
        foreach ($rows as $row) $sum=bcadd($sum,$row['outstanding'],2);
        self::assertSame($response->json('totals.outstanding'),$sum);

        $this->getJson('/api/reports/receivables?property_id='.$large->unit->property_id)
            ->assertOk()
            ->assertJsonPath('totals.outstanding','100.00')
            ->assertJsonCount(1,'by_property')
            ->assertJsonPath('by_property.0.name','Large Overdue');
    }

    public function test_property_breakdown_returns_only_top_twenty_and_keeps_all_aggregate_totals(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('aging-ranked-many@example.test');
        for($i=0;$i<22;$i++){
            $lease=$this->lease($owner,'Tower '.str_pad((string)$i,2,'0',STR_PAD_LEFT));
            $lease->dues()->create([
                'sequence'=>1,'due_on'=>'2027-01-01',
                'amount'=>sprintf('%d.00',$i+1)
            ]);
        }
        $this->actingAs($owner,'sanctum');
        $response=$this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('properties_with_balance',22)
            ->assertJsonPath('has_more_properties',true)
            ->assertJsonPath('totals.outstanding','253.00')
            ->assertJsonCount(20,'by_property');
        self::assertSame('Tower 21',$response->json('by_property.0.name'));
        self::assertSame('Tower 02',$response->json('by_property.19.name'));
    }

    public function test_report_refuses_guest_and_cross_owner_ledger_data(): void
    {
        $this->getJson('/api/reports/receivables')->assertUnauthorized();
        $owner=$this->owner('aging-empty@example.test');
        $other=$this->owner('aging-other@example.test');
        $foreign=$this->lease($other,'Secret Building');
        $foreign->dues()->create([
            'sequence'=>1,'due_on'=>'2020-01-01','amount'=>'550.00'
        ]);
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonPath('totals.outstanding','0.00')
            ->assertJsonCount(0,'oldest_overdue');
    }
}
