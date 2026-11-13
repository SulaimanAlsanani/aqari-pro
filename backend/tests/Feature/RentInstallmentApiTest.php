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
        return [$unit->id,$tenant->id];
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

    public function test_creation_rejects_more_installments_than_cents_or_invalid_decimals(): void
    {
        [$unitId,$tenantId]=$this->setupOwner();
        foreach ([['0.01',12],['0.001',1],['100.009',2]] as [$amount,$count]) {
            $this->postJson('/api/leases',$this->payload($unitId,$tenantId,$amount,$count))
                ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        }
        self::assertSame(0,Lease::query()->count());
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
