<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use App\Services\InstallmentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractCreationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $owner = Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Owner A']);
        $property = $owner->properties()->create(['name' => 'Building A', 'city' => 'Riyadh']);
        $unit = $property->units()->create(['code' => 'A-101']);
        $tenant = $owner->tenants()->create(['name' => 'Tenant A']);

        return [$user, $owner, $property, $unit, $tenant];
    }

    public function test_installment_schedule_is_exact_to_the_halalah_and_does_not_drift_on_month_end(): void
    {
        $rows = InstallmentSchedule::make('2026-01-31', '2026-04-30', '100.01', 4);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            array_column($rows, 'due_date'));
        $this->assertSame(['25.01', '25.00', '25.00', '25.00'], array_column($rows, 'amount'));
    }

    public function test_authenticated_owner_creates_contract_and_all_installments_atomically(): void
    {
        [$user, $owner, $property, $unit, $tenant] = $this->fixture();
        $this->actingAs($user)->postJson('/api/v1/contracts', [
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-01-31',
            'end_date' => '2026-04-30',
            'total' => '100.01',
            'installments' => 4,
            'owner_id' => 999,
        ])->assertCreated()->assertJsonCount(4, 'data.dues');

        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('dues', 4);

        $this->getJson('/api/v1/contracts')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contracts/1')->assertOk()->assertJsonCount(4, 'data.dues');
    }

    public function test_contract_rejects_overlapping_period_including_shared_boundary_day(): void
    {
        [$user, $owner, $property, $unit, $tenant] = $this->fixture();
        $payload = [
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'total' => '1200.00', 'installments' => 12,
        ];
        $this->actingAs($user)->postJson('/api/v1/contracts', $payload)->assertCreated();
        $this->postJson('/api/v1/contracts', array_merge($payload, [
            'start_date' => '2026-12-31', 'end_date' => '2027-12-30',
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('dues', 12);
    }

    public function test_cross_owner_unit_and_tenant_references_are_not_accessible(): void
    {
        [$userA, $ownerA, $propertyA, $unitA, $tenantA] = $this->fixture();
        [$userB, $ownerB, $propertyB, $unitB, $tenantB] = $this->fixture();
        $payload = [
            'unit_id' => $unitB->id, 'tenant_id' => $tenantA->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'total' => '1200', 'installments' => 12,
        ];

        $this->actingAs($userA)->postJson('/api/v1/contracts', $payload)->assertNotFound();
        $this->postJson('/api/v1/contracts', array_merge($payload, [
            'unit_id' => $unitA->id, 'tenant_id' => $tenantB->id,
        ]))->assertNotFound();

        $this->actingAs($userB)->postJson('/api/v1/contracts', array_merge($payload, [
            'tenant_id' => $tenantB->id,
        ]))->assertCreated();

        $this->actingAs($userA)->getJson('/api/v1/contracts/1')->assertNotFound();
        $this->getJson('/api/v1/contracts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_installment_window_does_not_write_partial_rows(): void
    {
        [$user, $owner, $property, $unit, $tenant] = $this->fixture();
        $this->actingAs($user)->postJson('/api/v1/contracts', [
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
            'total' => '1200', 'installments' => 12,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('dues', 0);
    }

    public function test_guest_cannot_list_or_create_contracts(): void
    {
        $this->getJson('/api/v1/contracts')->assertUnauthorized();
        $this->postJson('/api/v1/contracts', [])->assertUnauthorized();
    }
}
