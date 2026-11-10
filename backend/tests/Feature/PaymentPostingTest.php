<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPostingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $owner = Owner::query()->forceCreate(['user_id' => $user->id, 'name' => 'Owner']);
        $property = $owner->properties()->create(['name' => 'Building', 'city' => 'Riyadh']);
        $unit = $property->units()->create(['code' => '101']);
        $tenant = $owner->tenants()->create(['name' => 'Tenant']);
        $contract = \App\Models\Contract::query()->create([
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'total' => '100.01', 'installments' => 1, 'status' => 'draft',
        ]);
        $due = $contract->dues()->create(['due_date' => '2026-01-01', 'amount' => '100.01']);

        return [$user, $due];
    }

    public function test_partial_payments_are_exact_and_final_payment_clears_balance(): void
    {
        [$user, $due] = $this->fixture();
        $this->actingAs($user)->postJson("/api/v1/dues/{$due->id}/payments", [
            'amount' => '40.01', 'received_at' => '2026-01-02', 'method' => 'bank',
            'reference' => 'TX-1',
        ])->assertCreated()->assertJsonPath('data.amount', '40.01');

        $this->postJson("/api/v1/dues/{$due->id}/payments", [
            'amount' => '60.00', 'received_at' => '2026-01-03', 'method' => 'bank',
            'reference' => 'TX-2',
        ])->assertCreated();

        $this->postJson("/api/v1/dues/{$due->id}/payments", [
            'amount' => '0.01', 'received_at' => '2026-01-04', 'method' => 'bank',
            'reference' => 'TX-3',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('payments', 2);
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_duplicate_reference_for_same_due_and_method_is_rejected(): void
    {
        [$user, $due] = $this->fixture();
        $this->actingAs($user)->postJson("/api/v1/dues/{$due->id}/payments", [
            'amount' => '10', 'received_at' => '2026-01-02',
            'method' => 'bank', 'reference' => 'receipt-A',
        ])->assertCreated();

        $this->postJson("/api/v1/dues/{$due->id}/payments", [
            'amount' => '10', 'received_at' => '2026-01-03',
            'method' => 'bank', 'reference' => 'RECEIPT-a',
        ])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_invalid_amounts_are_rejected_without_changes(): void
    {
        [$user, $due] = $this->fixture();
        $this->actingAs($user);
        foreach (['0', '0.00', '0.001', '101', '-10'] as $amount) {
            $this->postJson("/api/v1/dues/{$due->id}/payments", [
                'amount' => $amount, 'received_at' => '2026-01-02',
                'method' => 'cash',
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_owner_cannot_post_or_read_receipts_for_another_owner(): void
    {
        [$userA] = $this->fixture();
        [$userB, $dueB] = $this->fixture();

        $this->actingAs($userB)->postJson("/api/v1/dues/{$dueB->id}/payments", [
            'amount' => '25', 'received_at' => '2026-01-02',
            'method' => 'bank', 'reference' => 'B',
        ])->assertCreated()->assertJsonPath('data.id', 1);

        $this->actingAs($userA);
        $this->postJson("/api/v1/dues/{$dueB->id}/payments", [
            'amount' => '25', 'received_at' => '2026-01-02', 'method' => 'cash',
        ])->assertNotFound();
        $this->getJson('/api/v1/payments/1')->assertNotFound();
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guest_cannot_post_receipts(): void
    {
        $this->postJson('/api/v1/dues/1/payments', [])->assertUnauthorized();
    }
}
