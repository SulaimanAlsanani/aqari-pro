<?php
namespace Tests\Feature;

use App\Models\{Lease,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RentInstallmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupOwner(): array
    {
        $owner=User::create([
            'name'=>'Rent precision test owner',
            'email'=>'rent-precision@example.test',
            'password'=>'test-password-long',
        ]);
        $property=$owner->properties()->create([
            'name'=>'Precision building','city'=>'Riyadh','type'=>'residential',
        ]);
        $unit=$property->units()->create(['code'=>'P-101','type'=>'apartment']);
        $tenant=$owner->tenants()->create(['name'=>'Precision tenant','phone'=>'0500000000']);
        $this->actingAs($owner,'sanctum');
        return [$unit->id,$tenant->id,$property->id];
    }

    private function payload(int $unitId,int $tenantId,string $amount,int $installments): array
    {
        return [
            'unit_id'=>$unitId,'tenant_id'=>$tenantId,
            'starts_on'=>'2027-01-01','ends_on'=>'2027-12-31',
            'total_amount'=>$amount,'installments'=>$installments,
        ];
    }

    public function test_lease_schedule_uses_exact_cents_and_preserves_rent_total(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        $id=$this->postJson('/api/leases',$this->payload($unitId,$tenantId,'0.05',4))
            ->assertCreated()->json('id');
        $lease=Lease::findOrFail($id);
        $amounts=$lease->dues()->orderBy('sequence')->get()->pluck('amount')->all();
        self::assertSame(['0.02','0.01','0.01','0.01'],$amounts);
    }

    public function test_short_leases_reject_installment_frequencies_that_create_duplicate_end_dates(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        $data=$this->payload($unitId,$tenantId,'1200.00',12);
        $data['ends_on']='2027-02-28';

        $this->postJson('/api/leases',$data)
            ->assertUnprocessable()->assertJsonValidationErrors('installments');
        self::assertSame(0,Lease::query()->count());

        $data['installments']=1;
        $leaseId=$this->postJson('/api/leases',$data)
            ->assertCreated()->json('id');
        $dates=Lease::findOrFail($leaseId)->dues()->orderBy('sequence')->pluck('due_on')->all();
        self::assertCount(1,$dates);
    }

    public function test_month_end_lease_installments_keep_calendar_dates_without_overflow(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        $data=$this->payload($unitId,$tenantId,'1200.00',12);
        $data['starts_on']='2027-01-31';
        $data['ends_on']='2028-01-30';

        $id=$this->postJson('/api/leases',$data)->assertCreated()->json('id');
        $dates=Lease::findOrFail($id)->dues()->orderBy('sequence')->get()
            ->map(fn($due)=>$due->due_on->toDateString())->all();
        self::assertCount(12,$dates);
        self::assertSame('2027-01-31',$dates[0]);
        self::assertSame('2027-02-28',$dates[1]);
        self::assertSame('2027-03-31',$dates[2]);
        self::assertSame('2027-12-31',$dates[11]);
    }

    public function test_partial_receipts_allocate_every_halalah_and_reject_excess_collection(): void
    {
        [$unitId,$tenantId,$propertyId]=$this->setupOwner();
        $leaseId=$this->postJson('/api/leases',$this->payload($unitId,$tenantId,'0.05',4))
            ->assertCreated()->json('id');

        $first=$this->postJson('/api/leases/'.$leaseId.'/payments',[
            'amount'=>'0.03','paid_on'=>'2027-01-05','method'=>'cash',
        ])->assertCreated();
        $allocations=$first->json('allocations');
        self::assertCount(2,$allocations);
        self::assertSame(
            ['0.02','0.01'],
            array_map(fn($item)=>(string)$item['amount'],$allocations)
        );

        $second=$this->postJson('/api/leases/'.$leaseId.'/payments',[
            'amount'=>'0.02','paid_on'=>'2027-01-06','method'=>'cash',
        ])->assertCreated();
        self::assertCount(2,$second->json('allocations'));

        $dues=$this->getJson('/api/dues')->assertOk();
        foreach ($dues->json('data') as $due) {
            self::assertSame('0.00',(string)$due['balance_amount']);
        }

        $this->postJson('/api/properties/'.$propertyId.'/expenses',[
            'category'=>'maintenance','description'=>'Synthetic one-halalah cost',
            'amount'=>'0.001','incurred_on'=>'2027-01-06',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/properties/'.$propertyId.'/expenses',[
            'category'=>'maintenance','description'=>'Synthetic one-halalah cost',
            'amount'=>'0.01','incurred_on'=>'2027-01-06',
        ])->assertCreated();
        $this->getJson('/api/overview')->assertOk()
            ->assertJsonPath('received','0.05')
            ->assertJsonPath('expenses','0.01')
            ->assertJsonPath('net_cashflow','0.04');

        $this->postJson('/api/leases/'.$leaseId.'/payments',[
            'amount'=>'0.01','paid_on'=>'2027-01-07','method'=>'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_creation_rejects_more_installments_than_cents_or_invalid_decimals(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        foreach ([['0.01',12],['0.001',1],['100.009',2]] as [$amount,$count]) {
            $this->postJson('/api/leases',$this->payload($unitId,$tenantId,$amount,$count))
                ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        }
        self::assertSame(0,Lease::query()->count());
    }

    public function test_renewal_rejects_zero_value_installments_without_creating_a_contract(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        $oldId=$this->postJson('/api/leases',$this->payload($unitId,$tenantId,'12.00',1))
            ->assertCreated()->json('id');
        $this->postJson('/api/leases/'.$oldId.'/activate')->assertOk();
        $this->postJson('/api/leases/'.$oldId.'/renew',[
            'total_amount'=>'0.11','installments'=>12,
        ])->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        self::assertSame(1,Lease::query()->count());
    }

    public function test_renewal_uses_identical_precise_money_split(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        $oldId=$this->postJson('/api/leases',$this->payload($unitId,$tenantId,'12.00',1))
            ->assertCreated()->json('id');
        $this->postJson('/api/leases/'.$oldId.'/activate')->assertOk();
        $nextId=$this->postJson('/api/leases/'.$oldId.'/renew',[
            'total_amount'=>'0.05','deposit'=>'0.00','installments'=>4,
        ])->assertCreated()->json('id');
        self::assertSame(
            ['0.02','0.01','0.01','0.01'],
            Lease::findOrFail($nextId)->dues()->orderBy('sequence')->get()->pluck('amount')->all()
        );
    }
}
