<?php

namespace Tests\Feature\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TransmittalDirection;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\DashboardMetrics;
use App\Services\Transmittals\TransmittalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private DashboardMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 0));
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        Office::create(['id' => 3, 'name' => 'CSC Field Office - Biliran', 'code' => 'FO-BILIRAN']);
        $this->metrics = app(DashboardMetrics::class);
    }

    private function make(TransactionStatus $status, string $filed = '2026-11-14', int $office = 2, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, 'office_id' => $office, 'transaction_date' => $filed.' 09:00:00', ...$extra]);
    }

    private function kpi(string $key): array
    {
        return collect($this->metrics->kpis())->firstWhere('key', $key);
    }

    public function test_the_kpis_count_what_they_say_and_compare_with_the_period_before(): void
    {
        $this->make(TransactionStatus::Submitted, '2026-11-14');
        $this->make(TransactionStatus::Submitted, '2026-11-14');
        $this->make(TransactionStatus::Submitted, '2026-11-13');
        $this->make(TransactionStatus::Forwarded, '2026-11-02');
        $this->make(TransactionStatus::Received, '2026-10-30');
        $this->make(TransactionStatus::Completed, '2026-10-20', 2, ['transaction_type' => TransactionType::Certification]);
        $this->make(TransactionStatus::Approved, '2026-10-20');
        $this->make(TransactionStatus::Released, '2026-10-01', 2, ['transaction_type' => TransactionType::Certification, 'doc_release_date' => '2026-11-05']);
        $this->make(TransactionStatus::Released, '2026-09-01', 2, ['transaction_type' => TransactionType::Certification, 'doc_release_date' => '2026-10-05']);

        $this->assertSame(2, $this->kpi('filed_today')['value']);
        $this->assertNull($this->kpi('filed_today')['delta'], 'A part-day figure is not given a percentage.');
        $this->assertSame('1 filed yesterday', $this->kpi('filed_today')['compare']);
        $this->assertCount(14, $this->kpi('filed_today')['spark']);
        $this->assertSame([1, 2], array_slice($this->kpi('filed_today')['spark'], -2));

        $this->assertSame(6, $this->kpi('open')['value'], 'Submitted x3, forwarded, received and completed');
        $this->assertSame(1, $this->kpi('awaiting_receipt')['value']);
        $this->assertSame(2, $this->kpi('ready_release')['value']);
        $this->assertSame(1, $this->kpi('released_month')['value']);
        $this->assertSame(0.0, $this->kpi('released_month')['delta']);
    }

    public function test_the_money_kpi_sums_the_months_amounts(): void
    {
        $this->make(TransactionStatus::Submitted, '2026-11-03', 2, ['amount' => 500]);
        $this->make(TransactionStatus::Submitted, '2026-11-10', 2, ['amount' => 200]);
        $this->make(TransactionStatus::Submitted, '2026-10-10', 2, ['amount' => 350]);

        $kpi = $this->kpi('revenue_month');

        $this->assertSame(700, $kpi['value']);
        $this->assertSame('peso', $kpi['format']);
        $this->assertSame(100.0, $kpi['delta']);
    }

    public function test_a_young_month_is_compared_with_the_same_days_of_last_month_only(): void
    {
        // Today is November 14th: compare November 1-14 with October 1-14.
        $this->make(TransactionStatus::Submitted, '2026-11-05', 2, ['amount' => 600]);
        $this->make(TransactionStatus::Submitted, '2026-10-05', 2, ['amount' => 300]);
        $this->make(TransactionStatus::Submitted, '2026-10-25', 2, ['amount' => 5000]);

        $kpi = $this->kpi('revenue_month');

        $this->assertSame(600, $kpi['value']);
        $this->assertSame(100.0, $kpi['delta'], 'Against 300, not against 5,300.');
        $this->assertSame('vs same days last month', $kpi['compare']);
    }

    public function test_a_change_from_nothing_has_no_percentage(): void
    {
        $this->make(TransactionStatus::Submitted, '2026-11-14', 2, ['amount' => 100]);

        $this->assertNull($this->kpi('filed_today')['delta']);
        $this->assertNull($this->kpi('revenue_month')['delta']);
    }

    public function test_the_trends_are_zero_filled_and_split_by_family(): void
    {
        $this->make(TransactionStatus::Submitted, '2026-11-14');
        $this->make(TransactionStatus::Received, '2026-11-14', 2, ['transaction_type' => TransactionType::Authentication]);
        $this->make(TransactionStatus::Submitted, '2026-11-03');
        $this->make(TransactionStatus::Submitted, '2026-09-20');
        $this->make(TransactionStatus::Submitted, '2025-01-01');

        $trends = $this->metrics->trends();

        $this->assertCount(30, $trends['daily']['labels']);
        $this->assertSame('Nov 14', end($trends['daily']['labels']));
        $this->assertSame(1, end($trends['daily']['spels']));
        $this->assertSame(1, end($trends['daily']['issuance']));
        $this->assertSame(3, $trends['daily']['total']);

        $this->assertCount(12, $trends['monthly']['labels']);
        $this->assertSame('Dec 2025', $trends['monthly']['labels'][0]);
        $this->assertSame('Nov 2026', $trends['monthly']['labels'][11]);
        $this->assertSame(2, $trends['monthly']['spels'][11]);
        $this->assertSame(1, $trends['monthly']['issuance'][11]);
        $this->assertSame(1, $trends['monthly']['spels'][9], 'September');
        $this->assertSame(4, $trends['monthly']['total'], 'The 2025 record is outside the window.');
    }

    public function test_the_status_mix_accounts_for_every_transaction(): void
    {
        foreach ([TransactionStatus::Submitted, TransactionStatus::Forwarded, TransactionStatus::Received, TransactionStatus::Completed, TransactionStatus::Approved, TransactionStatus::Released, TransactionStatus::ApprovedReleased, TransactionStatus::Disapproved, TransactionStatus::Returned, TransactionStatus::Cancelled] as $status) {
            $this->make($status);
        }

        $mix = collect($this->metrics->statusMix())->pluck('value', 'key');

        $this->assertSame(['open' => 3, 'ready' => 2, 'done' => 2, 'not_granted' => 3], $mix->all());
        $this->assertSame(Transaction::count(), $mix->sum());
    }

    public function test_the_rankings_are_ordered_and_limited_to_the_last_twelve_months(): void
    {
        foreach ([2, 2, 2, 3, 3, 1] as $office) {
            $this->make(TransactionStatus::Submitted, '2026-10-01', $office, ['elig_code' => $office === 2 ? 'HGE' : 'BOE']);
        }
        $this->make(TransactionStatus::Submitted, '2024-01-01', 3);
        $this->make(TransactionStatus::Submitted, '2026-10-01', 2, ['elig_code' => null]);

        $this->assertSame([['label' => 'FO-SAMAR', 'value' => 4], ['label' => 'FO-BILIRAN', 'value' => 2], ['label' => 'ESD', 'value' => 1]], $this->metrics->topOffices());
        // Equal counts have no fixed order, so compare as a set.
        $this->assertEqualsCanonicalizing(
            [['label' => 'HGE', 'value' => 3], ['label' => 'BOE', 'value' => 3], ['label' => 'Unspecified', 'value' => 1]],
            $this->metrics->topEligibilities(),
        );
    }

    public function test_the_turnaround_averages_days_from_filing_to_completion(): void
    {
        $this->make(TransactionStatus::Approved, '2026-11-01', 2, ['date_completed' => '2026-11-05']);
        $this->make(TransactionStatus::Approved, '2026-11-01', 2, ['date_completed' => '2026-11-09']);
        $this->make(TransactionStatus::Completed, '2026-11-10', 2, ['transaction_type' => TransactionType::Certification, 'date_completed' => '2026-11-11']);
        $this->make(TransactionStatus::Approved, '2025-01-01', 2, ['date_completed' => '2025-01-20']);

        $turnaround = $this->metrics->turnaround();

        $this->assertSame(['days' => 6.0, 'count' => 2], $turnaround['spels']);
        $this->assertSame(['days' => 1.0, 'count' => 1], $turnaround['issuance']);
    }

    public function test_nothing_completed_means_no_average_not_zero(): void
    {
        $this->assertSame(['days' => null, 'count' => 0], $this->metrics->turnaround()['spels']);
    }

    public function test_the_attention_lists_name_only_work_that_is_waiting(): void
    {
        $this->make(TransactionStatus::Forwarded, '2026-10-01', 2, ['date_sent_to_ro' => '2026-11-01', 'lname' => 'Cruz', 'fname' => 'Ana']);
        $this->make(TransactionStatus::Forwarded, '2026-10-01', 3, ['date_sent_to_ro' => '2026-11-10']);
        $this->make(TransactionStatus::Approved, '2026-10-01', 2, ['date_completed' => '2026-10-10']);
        $this->make(TransactionStatus::Completed, '2026-10-01', 2, ['transaction_type' => TransactionType::Certification, 'date_completed' => '2026-10-20']);
        $this->make(TransactionStatus::Approved, '2026-10-01', 3, ['date_completed' => '2026-11-01']);

        $attention = $this->metrics->attention();

        $this->assertSame('Cruz, Ana', $attention['awaitingReceipt'][0]['name']);
        $this->assertSame(13, $attention['awaitingReceipt'][0]['days']);
        $this->assertSame('FO-SAMAR', $attention['awaitingReceipt'][0]['office']);
        $this->assertCount(2, $attention['awaitingReceipt']);

        $this->assertSame([['office' => 'FO-SAMAR', 'office_id' => 2, 'count' => 2, 'oldest' => '2026-10-10'], ['office' => 'FO-BILIRAN', 'office_id' => 3, 'count' => 1, 'oldest' => '2026-11-01']], $attention['releaseBacklog']);
    }

    public function test_only_old_drafts_are_flagged_as_stale(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(TransmittalService::class);
        $old = $service->createDraft(TransmittalDirection::EsdToFo, [$this->make(TransactionStatus::Approved, '2026-10-01', 2)->id], '', $admin);
        $fresh = $service->createDraft(TransmittalDirection::EsdToFo, [$this->make(TransactionStatus::Approved, '2026-10-01', 3)->id], '', $admin);
        DB::table('transmittals')->where('id', $old->id)->update(['created_at' => now()->subDays(12)]);

        $drafts = $this->metrics->attention()['staleDrafts'];

        $this->assertCount(1, $drafts);
        $this->assertSame($old->transmittal_no, $drafts[0]['number']);
        $this->assertSame(12, $drafts[0]['days']);
        $this->assertNotContains($fresh->transmittal_no, array_column($drafts, 'number'));
    }

    public function test_the_activity_feed_mixes_current_and_legacy_entries_newest_first(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'esd.officer']);
        DB::table('legacy_activity_logs')->insert(['id' => 1, 'record_id' => 5, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'details' => 'Old entry.', 'created_at' => '2026-11-01 08:00:00']);
        AuditLog::record('transaction.created', $this->make(TransactionStatus::Submitted), after: ['summary' => 'New entry.'], user: $admin);

        $feed = $this->metrics->activity();

        $this->assertSame('New entry.', $feed[0]['details']);
        $this->assertSame('esd.officer', $feed[0]['who']);
        $this->assertSame('Old entry.', $feed[1]['details']);
    }

    public function test_everything_loads_on_an_empty_system(): void
    {
        $all = $this->metrics->all();

        $this->assertCount(6, $all['kpis']);
        $this->assertSame(0, $all['trends']['monthly']['total']);
        $this->assertSame([], $all['topOffices']);
        $this->assertSame([], $all['attention']['awaitingReceipt']);
    }
}
