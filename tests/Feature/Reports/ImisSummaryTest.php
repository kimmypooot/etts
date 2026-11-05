<?php

namespace Tests\Feature\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImisSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
    }

    private function feed(?string $key = 'secret-key')
    {
        return $this->withHeaders($key ? ['Authorization' => "Bearer {$key}"] : [])->getJson('/api/imis/summary');
    }

    public function test_the_feed_is_off_until_a_key_is_set(): void
    {
        config(['services.imis.feed_key' => null]);

        $this->feed()->assertNotFound();
    }

    public function test_a_wrong_or_missing_key_is_refused(): void
    {
        config(['services.imis.feed_key' => 'secret-key']);

        $this->feed('wrong')->assertUnauthorized();
        $this->feed(null)->assertUnauthorized();
    }

    public function test_the_feed_follows_the_imis_format(): void
    {
        config(['services.imis.feed_key' => 'secret-key']);
        Transaction::factory()->create(['transaction_status' => TransactionStatus::Received, 'office_id' => 2, 'transaction_date' => now(), 'amount' => 500]);
        Transaction::factory()->create(['transaction_status' => TransactionStatus::Forwarded, 'office_id' => 2, 'transaction_date' => now()]);
        Transaction::factory()->create(['transaction_status' => TransactionStatus::Approved, 'office_id' => 1, 'transaction_type' => TransactionType::Spels, 'transaction_date' => now(), 'date_completed' => today()]);

        $body = $this->feed()->assertOk()->json();

        $this->assertSame(1, $body['version']);
        $this->assertNotEmpty($body['generated_at']);
        $this->assertLessThanOrEqual(50, count($body['metrics']));

        $keys = array_column($body['metrics'], 'key');
        $this->assertSame($keys, array_values(array_unique($keys)), 'Keys must be unique.');

        foreach ($body['metrics'] as $metric) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $metric['key']);
            $this->assertLessThanOrEqual(100, strlen($metric['key']));
            $this->assertLessThanOrEqual(120, strlen($metric['label']));
            $this->assertContains($metric['unit'], ['count', 'percent', 'days', 'hours', 'peso']);
            $this->assertTrue($metric['value'] === null || is_numeric($metric['value']));
            $this->assertLessThanOrEqual(50, count($metric['breakdown'] ?? []));
        }

        $byKey = collect($body['metrics'])->keyBy('key');
        $this->assertSame(2, $byKey['etts.open']['value'], 'Received and forwarded; the approved one is not open work.');
        $this->assertSame(1, $byKey['etts.awaiting_receipt']['value']);
        $this->assertSame(1, $byKey['etts.ready_to_release']['value']);
        $this->assertSame(500, $byKey['etts.collected_this_month']['value']);
        $this->assertContains(['label' => 'FO-SAMAR', 'value' => 2], $byKey['etts.open']['breakdown']);
    }

    public function test_the_feed_holds_totals_only_never_a_person(): void
    {
        config(['services.imis.feed_key' => 'secret-key']);
        Transaction::factory()->create(['lname' => 'Zamora', 'fname' => 'Quirino', 'mobile' => '09171234567', 'email' => 'q@example.com', 'official_receipt' => 'OR-777', 'action_officer' => 'esd.officer', 'transaction_date' => now()]);

        $raw = $this->feed()->assertOk()->getContent();

        foreach (['Zamora', 'Quirino', '09171234567', 'q@example.com', 'OR-777', 'esd.officer'] as $private) {
            $this->assertStringNotContainsString($private, $raw);
        }
    }

    public function test_the_feed_works_with_no_data(): void
    {
        config(['services.imis.feed_key' => 'secret-key']);

        $metrics = collect($this->feed()->assertOk()->json('metrics'))->keyBy('key');

        $this->assertSame(0, $metrics['etts.open']['value']);
        $this->assertNull($metrics['etts.days_to_complete_spels']['value']);
    }

    // ─── Duplicate audit ──────────────────────────────────────────────────

    public function test_the_duplicate_audit_page_lists_groups_for_administrators_only(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['2025-01-10', '2025-06-10'] as $date) {
            Transaction::factory()->approved($date)->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2]);
        }

        $this->actingAs($admin)->get('/admin/audit/duplicates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audit/Duplicates')
                ->where('report.groupCount', 1)
                ->where('report.groups.0.lname', 'Cruz')
                ->has('report.groups.0.records', 2));

        $this->actingAs($admin)->get('/admin/audit/duplicates?eligibility=BOE')->assertInertia(fn (Assert $page) => $page->where('report.groupCount', 0));
        $this->actingAs($admin)->get('/admin/audit/duplicates?from=2025-05-01&to=2025-12-31')->assertInertia(fn (Assert $page) => $page->where('report.groupCount', 1));
    }

    public function test_the_dashboard_loads_its_figures_for_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        Transaction::factory()->create(['transaction_date' => now()]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('kpis', 6)
                ->has('trends.daily.labels', 30)
                ->has('trends.monthly.labels', 12)
                ->has('statusMix', 4)
                ->has('attention.awaitingReceipt')
                ->has('activity')
                ->where('alertDays.receipt', 3));
    }
}
