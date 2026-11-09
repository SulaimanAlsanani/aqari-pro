<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_properties(): void
    {
        $this->getJson('/api/v1/properties')->assertUnauthorized();
    }

    public function test_one_owner_never_sees_another_owners_properties(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceOwner = Owner::query()->create(['user_id' => $alice->id, 'name' => 'Owner A']);
        $bobOwner = Owner::query()->create(['user_id' => $bob->id, 'name' => 'Owner B']);
        $own = Property::query()->create(['owner_id' => $aliceOwner->id, 'name' => 'A property', 'city' => 'Riyadh']);
        $other = Property::query()->create(['owner_id' => $bobOwner->id, 'name' => 'B property', 'city' => 'Jeddah']);

        $this->actingAs($alice)
            ->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['id' => $other->id, 'name' => 'B property']);

        $this->getJson('/api/v1/properties/'.$other->id)->assertNotFound();
        $this->patchJson('/api/v1/properties/'.$other->id, ['name' => 'Hijacked'])->assertNotFound();

        $this->assertDatabaseHas('properties', ['id' => $other->id, 'name' => 'B property']);
    }

    public function test_server_overrides_supplied_owner_id(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceOwner = Owner::query()->create(['user_id' => $alice->id, 'name' => 'Owner A']);
        $bobOwner = Owner::query()->create(['user_id' => $bob->id, 'name' => 'Owner B']);

        $this->actingAs($alice)
            ->postJson('/api/v1/properties', [
                'owner_id' => $bobOwner->id,
                'name' => 'My building',
                'city' => 'Riyadh',
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $aliceOwner->id);

        $this->assertDatabaseMissing('properties', [
            'owner_id' => $bobOwner->id,
            'name' => 'My building',
        ]);
    }

    public function test_account_without_owner_record_cannot_create_property(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/properties', ['name' => 'No owner', 'city' => 'Riyadh'])
            ->assertNotFound();
    }
}
