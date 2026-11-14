<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ReceivablesExportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email): User
    {
        return User::create([
            'name'=>'Export owner','email'=>$email,
            'password'=>'strong-password-for-testing'
        ]);
    }

    private function lease(User $owner, string $propertyName, string $tenantName): \App\Models\Lease
    {
        $property=$owner->properties()->create([
            'name'=>$propertyName,'city'=>'Riyadh','type'=>'residential'
        ]);
        $unit=$property->units()->create(['code'=>'A-101','type'=>'apartment']);
        $tenant=$owner->tenants()->create(['name'=>$tenantName,'phone'=>'0500000000']);
        return $unit->leases()->create([
            'tenant_id'=>$tenant->id,'starts_on'=>'2027-01-01',
            'ends_on'=>'2027-12-31','total_amount'=>'600.00',
            'deposit'=>'0.00','status'=>'active'
        ]);
    }

    public function test_export_contains_all_unpaid_rows_and_escapes_formulas_without_foreign_data(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('csv-aging-owner@example.test');
        $other=$this->owner('csv-aging-other@example.test');
        $lease=$this->lease($owner,'=HYPERLINK("https://unsafe.invalid")','+SUM(1,1)');
        $first=$lease->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'300.00'
        ]);
        $second=$lease->dues()->create([
            'sequence'=>2,'due_on'=>'2027-07-01','amount'=>'300.00'
        ]);
        $foreign=$this->lease($other,'Secret Other Owner Building','Other tenant');
        $foreign->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'9999.00'
        ]);

        $this->actingAs($owner,'sanctum');
        $this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'100.00','paid_on'=>'2027-06-10','method'=>'cash'
        ])->assertCreated();

        $response=$this->get('/api/exports/receivables-aging')->assertOk();
        $response->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $csv=$response->streamedContent();
        self::assertStringContainsString("'\x3dHYPERLINK",$csv);
        self::assertStringContainsString("'+SUM(1,1)",$csv);
        self::assertStringContainsString('200.00',$csv);
        self::assertStringContainsString('300.00',$csv);
        self::assertStringContainsString('غير متأخر',$csv);
        self::assertStringNotContainsString('Secret Other Owner Building',$csv);
        self::assertStringNotContainsString('9999.00',$csv);

        $rows=array_map('str_getcsv',array_slice(explode("\n",trim($csv)),1));
        // The CSV header was sliced off, so exactly two unpaid rows remain.
        self::assertCount(2,$rows);
        self::assertSame((string)$first->id,$rows[0][1]);
        self::assertSame((string)$second->id,$rows[1][1]);
        self::assertSame('2027-06-15',$rows[0][0]);
        self::assertSame('200.00',$rows[0][9]);
        self::assertSame('300.00',$rows[1][9]);
    }

    public function test_receipt_void_restores_exported_balance_and_settled_dues_are_omitted(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('csv-aging-void@example.test');
        $lease=$this->lease($owner,'Test Tower','Tenant');
        $due=$lease->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'300.00'
        ]);
        $this->actingAs($owner,'sanctum');
        $paymentId=$this->postJson('/api/leases/'.$lease->id.'/payments',[
            'amount'=>'300.00','paid_on'=>'2027-06-10','method'=>'cash'
        ])->assertCreated()->json('id');

        $before=$this->get('/api/exports/receivables-aging')->assertOk()->streamedContent();
        self::assertCount(1,array_filter(explode("\n",trim($before)),fn($line)=>$line!==''));

        $this->postJson('/api/payments/'.$paymentId.'/void',[
            'reason'=>'Duplicate synthetic receipt entered in error'
        ])->assertOk();
        $after=$this->get('/api/exports/receivables-aging')->assertOk()->streamedContent();
        self::assertStringContainsString('300.00',$after);
        self::assertStringContainsString((string)$due->id,$after);
    }

    public function test_export_filters_owned_property_and_rejects_foreign_or_bad_filter(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('csv-aging-filter@example.test');
        $other=$this->owner('csv-aging-foreign@example.test');
        $one=$this->lease($owner,'First Tower','Resident');
        $two=$this->lease($owner,'Second Tower','Resident');
        $foreign=$this->lease($other,'Foreign Tower','Resident');
        foreach ([$one,$two,$foreign] as $lease) {
            $lease->dues()->create([
                'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'300.00'
            ]);
        }
        $this->actingAs($owner,'sanctum');
        $id=$one->unit->property_id;
        $csv=$this->get('/api/exports/receivables-aging?property_id='.$id)
            ->assertOk()->streamedContent();
        self::assertStringContainsString('First Tower',$csv);
        self::assertStringNotContainsString('Second Tower',$csv);
        self::assertStringNotContainsString('Foreign Tower',$csv);

        $this->get('/api/exports/receivables-aging?property_id='.$foreign->unit->property_id)
            ->assertNotFound();
        $this->getJson('/api/exports/receivables-aging?property_id=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('property_id');
    }

    public function test_export_includes_all_rows_beyond_twenty_item_dashboard_preview(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('csv-aging-many@example.test');
        $lease=$this->lease($owner,'Many Tower','Resident');
        for ($i=0;$i<25;$i++) {
            $lease->dues()->create([
                'sequence'=>$i+1,
                'due_on'=>Carbon::parse('2027-01-01')->addDays($i)->toDateString(),
                'amount'=>'1.00'
            ]);
        }
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/reports/receivables')->assertOk()
            ->assertJsonCount(20,'oldest_overdue')
            ->assertJsonPath('has_more_overdue',true);
        $csv=$this->get('/api/exports/receivables-aging')
            ->assertOk()->streamedContent();
        // CSV has one header line and all 25 outstanding installments.
        self::assertCount(26,explode("\n",trim($csv)));
    }

    public function test_corrupt_overallocation_rejects_request_before_stream_starts(): void
    {
        $this->travelTo(Carbon::parse('2027-06-15'));
        $owner=$this->owner('csv-aging-corrupt@example.test');
        $lease=$this->lease($owner,'Tower','Tenant');
        $due=$lease->dues()->create([
            'sequence'=>1,'due_on'=>'2027-01-01','amount'=>'100.00'
        ]);
        $payment=$lease->payments()->create([
            'amount'=>'200.00','paid_on'=>'2027-06-10','method'=>'cash'
        ]);
        $payment->allocations()->create([
            'due_id'=>$due->id,'amount'=>'200.00'
        ]);
        $this->actingAs($owner,'sanctum');
        $this->getJson('/api/exports/receivables-aging')->assertStatus(409);
    }

    public function test_anonymous_export_is_forbidden(): void
    {
        $this->getJson('/api/exports/receivables-aging')->assertUnauthorized();
    }
}
