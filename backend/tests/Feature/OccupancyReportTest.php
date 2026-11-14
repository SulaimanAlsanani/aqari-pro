<?php
namespace Tests\Feature;

use App\Models\{Lease,Property,Unit,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class OccupancyReportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email): User
    {
        return User::create([
            'name'=>'Occupancy test owner','email'=>$email,
            'password'=>'strong-password-for-testing'
        ]);
    }

    private function property(User $owner,string $name): Property
    {
        return $owner->properties()->create([
            'name'=>$name,'city'=>'Riyadh','type'=>'residential'
        ]);
    }

    private function unit(Property $property,string $code): Unit
    {
        return $property->units()->create(['code'=>$code,'type'=>'apartment']);
    }

    private function lease(User $owner,Unit $unit,string $status,string $from,string $to): Lease
    {
        $tenant=$owner->tenants()->create([
            'name'=>'Resident '.$unit->code,'phone'=>'0500000000'
        ]);
        return $unit->leases()->create([
            'tenant_id'=>$tenant->id,'starts_on'=>$from,'ends_on'=>$to,
            'total_amount'=>'1200.00','deposit'=>'0.00','status'=>$status
        ]);
    }

    public function test_occupancy_uses_only_active_leases_covering_today_and_shows_expiries(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15 12:00:00'));
        $owner=$this->owner('occ-owner@example.test');
        $other=$this->owner('occ-other@example.test');
        $one=$this->property($owner,'Tower One');
        $two=$this->property($owner,'Tower Two');
        $secret=$this->property($other,'Private Other Owner');

        $occupied=$this->unit($one,'O-101');
        $expired=$this->unit($one,'O-102');
        $draft=$this->unit($one,'O-103');
        $lastDay=$this->unit($two,'T-201');
        $future=$this->unit($two,'T-202');
        $otherUnit=$this->unit($secret,'Private-1');

        $this->lease($owner,$occupied,'active','2027-01-01','2027-07-01');
        $this->lease($owner,$expired,'active','2027-01-01','2027-05-01');
        $this->lease($owner,$draft,'draft','2027-01-01','2027-12-31');
        $this->lease($owner,$lastDay,'active','2027-01-01','2027-06-15');
        $this->lease($owner,$future,'active','2027-07-01','2027-12-31');
        $this->lease($other,$otherUnit,'active','2027-01-01','2027-12-31');

        $this->actingAs($owner,'sanctum');
        $result=$this->getJson('/api/reports/occupancy')->assertOk()
            ->assertJsonPath('as_of','2027-06-15')
            ->assertJsonPath('expires_through','2027-07-15')
            ->assertJsonPath('totals.properties',2)
            ->assertJsonPath('totals.units',5)
            ->assertJsonPath('totals.occupied',2)
            ->assertJsonPath('totals.vacant',3)
            ->assertJsonPath('totals.occupancy_percent','40.00')
            ->assertJsonPath('expiring_30_days_count',2)
            ->assertJsonCount(2,'by_property')
            ->assertJsonCount(2,'expiring_30_days');

        self::assertSame('Tower One',$result->json('by_property.0.name'));
        self::assertSame(2,$result->json('by_property.0.vacant'));
        self::assertSame('33.33',$result->json('by_property.0.occupancy_percent'));
        self::assertSame('Tower Two',$result->json('by_property.1.name'));
        self::assertSame('50.00',$result->json('by_property.1.occupancy_percent'));
        self::assertSame('2027-06-15',$result->json('expiring_30_days.0.ends_on'));
        self::assertSame(0,$result->json('expiring_30_days.0.days_until_end'));
        self::assertSame('2027-07-01',$result->json('expiring_30_days.1.ends_on'));
        self::assertNotContains('Private Other Owner',
            array_column($result->json('by_property'),'name'));
    }

    public function test_owner_can_filter_by_property_but_cannot_view_foreign_property(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('occ-filter@example.test');
        $other=$this->owner('occ-foreign@example.test');
        $first=$this->property($owner,'First');
        $second=$this->property($owner,'Second');
        $secret=$this->property($other,'Secret');
        $this->unit($first,'A');
        $this->unit($first,'B');
        $this->unit($second,'C');
        $this->unit($secret,'D');

        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/reports/occupancy')->assertOk()
            ->assertJsonPath('totals.units',3);
        $this->getJson('/api/reports/occupancy?property_id='.$first->id)
            ->assertOk()->assertJsonPath('property_id',$first->id)
            ->assertJsonPath('totals.properties',1)
            ->assertJsonPath('totals.units',2)
            ->assertJsonPath('totals.vacant',2)
            ->assertJsonCount(1,'by_property');
        $this->getJson('/api/reports/occupancy?property_id='.$secret->id)
            ->assertNotFound();
        $this->getJson('/api/reports/occupancy?property_id=not-a-number')
            ->assertUnprocessable()->assertJsonValidationErrors('property_id');
    }

    public function test_properties_without_units_show_zero_occupancy_and_preview_is_bounded(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('occ-many@example.test');
        for($i=0;$i<22;$i++) {
            $this->property($owner,'Empty '.str_pad((string)$i,2,'0',STR_PAD_LEFT));
        }
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/reports/occupancy')->assertOk()
            ->assertJsonPath('totals.properties',22)
            ->assertJsonPath('totals.units',0)
            ->assertJsonPath('totals.occupied',0)
            ->assertJsonPath('totals.vacant',0)
            ->assertJsonPath('totals.occupancy_percent','0.00')
            ->assertJsonPath('has_more_properties',true)
            ->assertJsonCount(20,'by_property')
            ->assertJsonCount(0,'expiring_30_days');
    }

    public function test_navigation_between_reports_does_not_share_throttle_counter(): void
    {
        $owner=$this->owner('occ-limits@example.test');
        $this->actingAs($owner,'sanctum');
        // These requests total 24 within one minute. With numeric middleware
        // both screens share a 20-request bucket and one screen receives 429.
        for($i=0;$i<12;$i++) {
            $this->getJson('/api/reports/occupancy')->assertOk();
            $this->getJson('/api/reports/receivables')->assertOk();
        }
    }

    public function test_repeated_occupancy_requests_still_have_a_bounded_quota(): void
    {
        $owner=$this->owner('occ-limits-enforce@example.test');
        $this->actingAs($owner,'sanctum');
        for($i=0;$i<30;$i++)$this->getJson('/api/reports/occupancy')->assertOk();
        $this->getJson('/api/reports/occupancy')->assertStatus(429);
    }

    public function test_guest_is_refused(): void
    {
        $this->getJson('/api/reports/occupancy')->assertUnauthorized();
    }
}
