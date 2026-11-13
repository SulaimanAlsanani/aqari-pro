<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operational smoke scenario for a newly restored owner portfolio.
 * Uses the actual owner API rather than querying a mocked dashboard.
 */
final class OwnerPortfolioRecoverySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_lease_schedule_and_collections_without_other_owners_data(): void
    {
        $owner=User::create([
            'name'=>'Recovery owner','email'=>'recovery-owner@example.test','password'=>'a-long-test-password',
        ]);
        $other=User::create([
            'name'=>'Different owner','email'=>'recovery-other@example.test','password'=>'a-long-test-password',
        ]);

        $this->actingAs($owner,'sanctum');

        $propertyId=$this->postJson('/api/properties',[
            'name'=>'Recovery Building','city'=>'Riyadh','type'=>'residential',
        ])->assertCreated()->json('id');

        $unitId=$this->postJson('/api/properties/'.$propertyId.'/units',[
            'code'=>'A-101','type'=>'apartment',
        ])->assertCreated()->json('id');

        $tenantId=$this->postJson('/api/tenants',[
            'name'=>'Recovery tenant','phone'=>'0500000000',
        ])->assertCreated()->json('id');

        $leaseId=$this->postJson('/api/leases',[
            'unit_id'=>$unitId,'tenant_id'=>$tenantId,
            'starts_on'=>'2026-01-01','ends_on'=>'2026-12-31',
            'total_amount'=>'1200.00','deposit'=>'100.00','installments'=>2,
        ])->assertCreated()->json('id');

        $this->getJson('/api/portfolio')->assertOk()
            ->assertJsonPath('properties.total',1)
            ->assertJsonPath('leases_count',1)
            ->assertJsonPath('tenants_count',1);
        $this->getJson('/api/units')->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/leases')->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/dues')->assertOk()->assertJsonPath('total',2)
            ->assertJsonPath('data.0.balance_amount','600.00');

        $receipt=[
            'amount'=>'600.00','paid_on'=>'2026-01-10',
            'method'=>'bank_transfer','reference'=>'RECOVERY-1',
        ];
        $this->withHeader('Idempotency-Key','recovery-smoke-001');
        $payment=$this->postJson('/api/leases/'.$leaseId.'/payments',$receipt)
            ->assertCreated()->json('id');
        $this->postJson('/api/leases/'.$leaseId.'/payments',$receipt)
            ->assertOk()->assertJsonPath('id',$payment);

        $this->getJson('/api/dues')->assertOk()
            ->assertJsonPath('data.0.paid_amount','600.00')
            ->assertJsonPath('data.0.balance_amount','0.00')
            ->assertJsonPath('data.1.balance_amount','600.00');

        $this->getJson('/api/payments')->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/overview')->assertOk()
            ->assertJsonPath('properties',1)
            ->assertJsonPath('units',1)
            ->assertJsonPath('tenants',1);

        $this->getJson('/api/reports/cashflow?from=2026-01-01&to=2026-12-31')
            ->assertOk()->assertJsonPath('totals.received','600.00');

        $this->flushHeaders();
        $this->actingAs($other,'sanctum');
        $this->getJson('/api/portfolio')->assertOk()
            ->assertJsonPath('properties.total',0)
            ->assertJsonPath('leases_count',0);
        $this->getJson('/api/leases')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/payments')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/dues')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/reports/cashflow?from=2026-01-01&to=2026-12-31')
            ->assertOk()->assertJsonPath('totals.received','0.00');
        $this->getJson('/api/properties/'.$propertyId)->assertNotFound();
        $this->postJson('/api/leases/'.$leaseId.'/payments',$receipt)->assertNotFound();
    }

    public function test_guest_cannot_access_restored_portfolio_or_financial_data(): void
    {
        foreach (['/api/portfolio','/api/units','/api/tenants','/api/leases',
            '/api/dues','/api/payments','/api/overview',
            '/api/reports/cashflow?from=2026-01-01&to=2026-12-31'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }
}
