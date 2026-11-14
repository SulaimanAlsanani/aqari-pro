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
