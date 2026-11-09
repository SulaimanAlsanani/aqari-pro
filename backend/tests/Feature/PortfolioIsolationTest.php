<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerFor(User $user, string $name): Owner
    {
        return Owner::query()->forceCreate(['user_id' => $user->id, 'name' => $name]);
    }

    public function test_guests_cannot_access_units_or_tenants(): void
    {
        $this->getJson('/api/v1/tenants')->assertUnauthorized();
        $this->getJson('/api/v1/properties/1/units')->assertUnauthorized();
    }

    public function test_units_are_restricted_by_property_ownership_and_parent(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $ownerA = $this->ownerFor($userA, 'A');
        $ownerB = $this->ownerFor($userB, 'B');
        $propertyA = $ownerA->properties()->create(['name' => 'A building', 'city' => 'Riyadh']);
        $propertyB = $ownerB->properties()->create(['name' => 'B building', 'city' => 'Jeddah']);
        $unitB = $propertyB->units()->create(['code' => '101']);

        $this->actingAs($userA);

        $this->getJson("/api/v1/properties/{$propertyB->id}/units")->assertNotFound();
        $this->postJson("/api/v1/properties/{$propertyB->id}/units", ['code' => '999'])->assertNotFound();
        $this->getJson("/api/v1/properties/{$propertyA->id}/units/{$unitB->id}")->assertNotFound();
        $this->patchJson("/api/v1/properties/{$propertyB->id}/units/{$unitB->id}", ['code' => 'HACK'])->assertNotFound();
        $this->assertDatabaseHas('units', ['id' => $unitB->id, 'code' => '101']);

        $this->postJson("/api/v1/properties/{$propertyA->id}/units", [
            'code' => '101',
            'property_id' => $propertyB->id,
            'area' => '75.50',
        ])->assertCreated()->assertJsonPath('data.property_id', $propertyA->id);

        $this->postJson("/api/v1/properties/{$propertyA->id}/units", ['code' => '101'])
            ->assertUnprocessable();

        $this->getJson("/api/v1/properties/{$propertyA->id}/units")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tenants_cannot_be_read_modified_or_reassigned_across_owners(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $ownerA = $this->ownerFor($userA, 'A');
        $ownerB = $this->ownerFor($userB, 'B');
        $otherTenant = $ownerB->tenants()->create(['name' => 'B tenant']);

        $this->actingAs($userA);

        $this->getJson('/api/v1/tenants/'.$otherTenant->id)->assertNotFound();
        $this->patchJson('/api/v1/tenants/'.$otherTenant->id, ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->postJson('/api/v1/tenants', [
            'name' => 'A tenant', 'phone' => '0500000000', 'owner_id' => $ownerB->id,
        ])->assertCreated()->assertJsonPath('data.owner_id', $ownerA->id);

        $this->getJson('/api/v1/tenants')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('tenants', ['id' => $otherTenant->id, 'name' => 'B tenant']);
    }
}
