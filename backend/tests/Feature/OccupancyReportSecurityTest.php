<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OccupancyReportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => $email,
            'password' => Hash::make('test-only-strong-password'),
        ]);
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/reports/occupancy')->assertUnauthorized();
    }

    public function test_empty_portfolio_returns_zero_counts_without_division_by_zero(): void
    {
        Sanctum::actingAs($this->owner('empty@example.test'));

        $this->getJson('/api/reports/occupancy')
            ->assertOk()
            ->assertJsonPath('totals.properties', 0)
            ->assertJsonPath('totals.units', 0)
            ->assertJsonPath('totals.occupied', 0)
            ->assertJsonPath('totals.vacant', 0)
            ->assertJsonPath('totals.occupancy_percent', '0.00')
            ->assertJsonPath('expiring_30_days_count', 0);
    }

    public function test_report_excludes_other_owners_properties_and_rejects_cross_owner_filter(): void
    {
        $owner = $this->owner('first@example.test');
        $other = $this->owner('second@example.test');

        $mine = $owner->properties()->create([
            'name' => 'My Property', 'city' => 'Riyadh',
            'district' => 'North', 'type' => 'residential', 'address' => 'Test address',
        ]);
        $theirs = $other->properties()->create([
            'name' => 'Private Property', 'city' => 'Jeddah',
            'district' => 'West', 'type' => 'residential', 'address' => 'Private address',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/reports/occupancy')
            ->assertOk()
            ->assertJsonPath('totals.properties', 1)
            ->assertJsonPath('totals.units', 0)
            ->assertJsonPath('by_property.0.property_id', $mine->id)
            ->assertDontSee('Private Property');

        $this->getJson('/api/reports/occupancy?property_id='.$mine->id)
            ->assertOk()
            ->assertJsonPath('property_id', $mine->id);

        $this->getJson('/api/reports/occupancy?property_id='.$theirs->id)
            ->assertNotFound();
    }

    public function test_property_filter_rejects_invalid_ids(): void
    {
        Sanctum::actingAs($this->owner('filter@example.test'));

        $this->getJson('/api/reports/occupancy?property_id=abc')
            ->assertUnprocessable();
        $this->getJson('/api/reports/occupancy?property_id=-1')
            ->assertUnprocessable();
    }
    public function test_report_limits_property_details_without_losing_global_totals(): void
    {
        $owner = $this->owner('many@example.test');
        $foreign = $this->owner('foreign@example.test');

        for ($index = 1; $index <= 23; $index++) {
            $owner->properties()->create([
                'name' => 'Property '.$index,
                'city' => 'Riyadh',
                'district' => 'Test district',
                'type' => 'residential',
                'address' => 'Test address',
            ]);
        }

        $foreign->properties()->create([
            'name' => 'Secret Foreign Property',
            'city' => 'Jeddah',
            'district' => 'Test district',
            'type' => 'residential',
            'address' => 'Private address',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/reports/occupancy')
            ->assertOk()
            ->assertJsonPath('totals.properties', 23)
            ->assertJsonPath('totals.units', 0)
            ->assertJsonPath('has_more_properties', true)
            ->assertJsonCount(20, 'by_property')
            ->assertDontSee('Secret Foreign Property');
    }

    public function test_report_rejects_nonexistent_property_filter_without_exposing_data(): void
    {
        Sanctum::actingAs($this->owner('missing@example.test'));

        $this->getJson('/api/reports/occupancy?property_id=99999999')
            ->assertNotFound();
    }

}
