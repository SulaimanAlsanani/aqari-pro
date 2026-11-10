<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivablesReversalTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $dueDate = '2026-01-01'): array
    {
        $user = User::factory()->create();
        $owner = Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Owner']);
        $property = $owner->properties()->create(['name' => 'Property', 'city' => 'Riyadh']);
        $unit = $property->units()->create(['code' => '101']);
        $tenant = $owner->tenants()->create(['name' => 'Tenant']);
        $contract = Contract::query()->create([
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'total' => '100.01', 'installments' => 1, 'status' => 'draft',
        ]);
        $due = $contract->dues()->create(['due_date' => $dueDate, 'amount' => '100.01']);

        return [$user, $property, $due];
    }

    private function postReceipt(int $dueId, string $amount, string $reference): int
    {
        $response = $this->postJson("/api/v1/dues/{$dueId}/payments", [
            'amount' => $amount, 'received_at' => '2026-01-02',
            'method' => 'bank', 'reference' => $reference,
        ])->assertCreated();

        return $response->json('data.id');
    }

    public function test_reversal_restores_receivable_and_preserves_original_receipt_and_events(): void
    {
        [$user, $property, $due] = $this->fixture();
        $this->actingAs($user);
        $paymentId = $this->postReceipt($due->id, '40.01', 'TRANSFER-A');

        $this->getJson('/api/v1/reports/receivables')
            ->assertOk()
            ->assertJsonPath('amounts.due', '100.01')
            ->assertJsonPath('amounts.collected', '40.01')
            ->assertJsonPath('amounts.outstanding', '60.00');

        $this->postJson("/api/v1/payments/{$paymentId}/reverse", [
            'reason' => 'Duplicate bank transfer record',
        ])->assertOk()->assertJsonPath('data.reversal_reason', 'Duplicate bank transfer record');

        $this->getJson('/api/v1/reports/receivables')
            ->assertOk()
            ->assertJsonPath('amounts.collected', '0.00')
            ->assertJsonPath('amounts.outstanding', '100.01');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_events', 2);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $paymentId, 'action' => 'posted']);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $paymentId, 'action' => 'reversed']);

        $this->postJson("/api/v1/payments/{$paymentId}/reverse", [
            'reason' => 'Attempt second reversal',
        ])->assertUnprocessable();

        $this->postReceipt($due->id, '40.01', 'TRANSFER-A');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_reversal_requires_reason_and_owner_access(): void
    {
        [$alice] = $this->fixture();
        [$bob, $property, $due] = $this->fixture();
        $this->actingAs($bob);
        $paymentId = $this->postReceipt($due->id, '25', 'B');

        $this->postJson("/api/v1/payments/{$paymentId}/reverse", [
            'reason' => 'x',
        ])->assertUnprocessable();

        $this->actingAs($alice)
            ->postJson("/api/v1/payments/{$paymentId}/reverse", [
                'reason' => 'Unauthorized attempt',
            ])->assertNotFound();

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'reversed_at' => null]);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_report_is_scoped_to_authenticated_owner_and_property(): void
    {
        [$alice, $propertyA, $dueA] = $this->fixture();
        [$bob, $propertyB, $dueB] = $this->fixture();

        $this->actingAs($alice);
        $this->postReceipt($dueA->id, '10.01', 'A');
        $this->getJson('/api/v1/reports/receivables?property_id='.$propertyA->id)
            ->assertOk()
            ->assertJsonPath('amounts.outstanding', '90.00')
            ->assertJsonPath('installments_count', 1);
        $this->getJson('/api/v1/reports/receivables?property_id='.$propertyB->id)
            ->assertNotFound();
        $this->getJson('/api/v1/reports/receivables')
            ->assertOk()->assertJsonPath('installments_count', 1);

        $this->actingAs($bob)->getJson('/api/v1/reports/receivables')
            ->assertOk()->assertJsonPath('amounts.due', '100.01');
    }

    public function test_guest_cannot_read_aging_or_reverse_payment(): void
    {
        $this->getJson('/api/v1/reports/receivables')->assertUnauthorized();
        $this->postJson('/api/v1/payments/1/reverse', [])->assertUnauthorized();
    }
}
