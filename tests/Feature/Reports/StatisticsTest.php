<?php

namespace Tests\Feature\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\StatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-01-01 00:00:00';

    private const TO = '2026-06-30 23:59:59';

    private StatisticsService $statistics;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->statistics = app(StatisticsService::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function cert(TransactionStatus $status, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_type' => TransactionType::Certification, 'transaction_status' => $status, 'office_id' => 1, 'transaction_date' => '2026-03-10 09:00:00', ...$extra]);
    }

    private function spels(TransactionStatus $status, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, 'office_id' => 2, 'transaction_date' => '2026-03-10 09:00:00', 'elig_code' => 'HGE', ...$extra]);
    }

    private function row(array $report, string $group): array
    {
        return collect($report['rows'])->firstWhere('group', $group);
    }

    // ─── Certifications and authentications ───────────────────────────────

    public function test_certifications_are_counted_by_type_and_stage(): void
    {
        $this->cert(TransactionStatus::Received, ['date_received_ro' => '2026-03-10']);
        $this->cert(TransactionStatus::Completed, ['date_received_ro' => '2026-03-10', 'date_completed' => '2026-03-11']);
        $this->cert(TransactionStatus::Released, ['date_received_ro' => '2026-03-10', 'date_completed' => '2026-03-11']);
        $this->cert(TransactionStatus::Cancelled, ['date_received_ro' => '2026-03-10']);
        $this->cert(TransactionStatus::Received, ['transaction_type' => TransactionType::Authentication, 'date_received_ro' => '2026-03-10']);
        $this->cert(TransactionStatus::Received, ['date_received_ro' => '2026-12-01', 'transaction_date' => '2026-12-01 09:00:00']);

        $report = $this->statistics->report('coe_auth', self::FROM, self::TO, null);

        $this->assertSame(['group' => 'Certification', 'filed' => 4, 'received' => 4, 'completed' => 2, 'released' => 1, 'cancelled' => 1, 'pending' => 1], $this->row($report, 'Certification'));
        $this->assertSame(['group' => 'Authentication', 'filed' => 1, 'received' => 1, 'completed' => 0, 'released' => 0, 'cancelled' => 0, 'pending' => 1], $this->row($report, 'Authentication'));
        $this->assertSame(5, $report['totals']['filed']);
        $this->assertSame(2, $report['totals']['pending']);
    }

    public function test_spels_applications_are_not_in_the_certification_report(): void
    {
        $this->spels(TransactionStatus::Submitted);

        $this->assertSame([], $this->statistics->report('coe_auth', self::FROM, self::TO, null)['rows']);
    }

    public function test_released_records_are_broken_down_by_officer_and_sex(): void
    {
        $this->cert(TransactionStatus::Released, ['action_officer' => 'ana', 'sex' => 'Female']);
        $this->cert(TransactionStatus::Released, ['action_officer' => 'ana', 'sex' => 'Female']);
        $this->cert(TransactionStatus::Released, ['action_officer' => 'ben', 'sex' => 'Male']);
        $this->cert(TransactionStatus::Released, ['action_officer' => null, 'sex' => null]);
        $this->cert(TransactionStatus::Received, ['action_officer' => 'ben', 'sex' => 'Male']);

        $report = $this->statistics->report('coe_auth', self::FROM, self::TO, null);

        $this->assertSame([['label' => 'ana', 'value' => 2], ['label' => 'ben', 'value' => 1], ['label' => 'Unassigned', 'value' => 1]], $report['officers']);
        $this->assertSame(2, collect($report['sexes'])->firstWhere('label', 'Female')['value']);
        $this->assertSame(1, collect($report['sexes'])->firstWhere('label', 'Not recorded')['value']);
    }

    // ─── Special eligibility applications ─────────────────────────────────

    public function test_each_spels_column_counts_by_its_own_date(): void
    {
        // Filed in January, forwarded and received in February, approved in March.
        $this->spels(TransactionStatus::Approved, ['transaction_date' => '2026-01-10 09:00:00', 'date_sent_to_ro' => '2026-02-01', 'date_received_ro' => '2026-02-05', 'date_completed' => '2026-03-03']);

        $january = $this->statistics->report('spels', '2026-01-01 00:00:00', '2026-01-31 23:59:59', null);
        $february = $this->statistics->report('spels', '2026-02-01 00:00:00', '2026-02-28 23:59:59', null);
        $march = $this->statistics->report('spels', '2026-03-01 00:00:00', '2026-03-31 23:59:59', null);

        $this->assertSame(['submitted' => 1, 'forwarded' => 0, 'received' => 0, 'approved' => 0], array_intersect_key($january['totals'], array_flip(['submitted', 'forwarded', 'received', 'approved'])));
        $this->assertSame(['submitted' => 0, 'forwarded' => 1, 'received' => 1, 'approved' => 0], array_intersect_key($february['totals'], array_flip(['submitted', 'forwarded', 'received', 'approved'])));
        $this->assertSame(['submitted' => 0, 'forwarded' => 0, 'received' => 0, 'approved' => 1], array_intersect_key($march['totals'], array_flip(['submitted', 'forwarded', 'received', 'approved'])));
    }

    public function test_decisions_and_pending_are_counted_separately(): void
    {
        $this->spels(TransactionStatus::Approved, ['date_completed' => '2026-03-03']);
        $this->spels(TransactionStatus::ApprovedReleased, ['date_completed' => '2026-03-04']);
        $this->spels(TransactionStatus::Disapproved, ['date_completed' => '2026-03-05']);
        $this->spels(TransactionStatus::Returned, ['date_completed' => '2026-03-06']);
        $this->spels(TransactionStatus::Received, ['date_received_ro' => '2026-03-07']);
        $this->spels(TransactionStatus::Received, ['date_received_ro' => '2026-09-07']);

        $totals = $this->statistics->report('spels', self::FROM, self::TO, null)['totals'];

        $this->assertSame(2, $totals['approved'], 'Approved and Approved/Released both count.');
        $this->assertSame(1, $totals['disapproved']);
        $this->assertSame(1, $totals['returned']);
        $this->assertSame(1, $totals['pending'], 'Only those received inside the range.');
    }

    public function test_applications_are_grouped_by_eligibility_with_an_unspecified_row(): void
    {
        $this->spels(TransactionStatus::Submitted, ['elig_code' => 'HGE']);
        $this->spels(TransactionStatus::Submitted, ['elig_code' => 'HGE']);
        $this->spels(TransactionStatus::Submitted, ['elig_code' => 'BOE']);
        $this->spels(TransactionStatus::Submitted, ['elig_code' => null]);
        $this->spels(TransactionStatus::Submitted, ['elig_code' => '']);

        $report = $this->statistics->report('spels', self::FROM, self::TO, null);

        $this->assertSame(['BOE', 'HGE', 'Unspecified'], array_column($report['rows'], 'group'));
        $this->assertSame(2, $this->row($report, 'HGE')['submitted']);
        $this->assertSame(2, $this->row($report, 'Unspecified')['submitted']);
    }

    public function test_the_office_filter_narrows_every_figure(): void
    {
        $this->spels(TransactionStatus::Submitted, ['office_id' => 2]);
        $this->spels(TransactionStatus::Submitted, ['office_id' => 1]);

        $this->assertSame(1, $this->statistics->report('spels', self::FROM, self::TO, 2)['totals']['submitted']);
        $this->assertSame(2, $this->statistics->report('spels', self::FROM, self::TO, null)['totals']['submitted']);
    }

    // ─── Drill-down: the list agrees with the count ───────────────────────

    public function test_every_cell_lists_exactly_the_records_it_counts(): void
    {
        $this->spels(TransactionStatus::Approved, ['date_sent_to_ro' => '2026-02-01', 'date_received_ro' => '2026-02-05', 'date_completed' => '2026-03-03']);
        $this->spels(TransactionStatus::Disapproved, ['elig_code' => 'BOE', 'date_received_ro' => '2026-02-06', 'date_completed' => '2026-03-04']);
        $this->spels(TransactionStatus::Received, ['elig_code' => null, 'date_received_ro' => '2026-03-07']);
        $this->cert(TransactionStatus::Released, ['date_received_ro' => '2026-03-10', 'date_completed' => '2026-03-11']);
        $this->cert(TransactionStatus::Received, ['transaction_type' => TransactionType::Authentication, 'date_received_ro' => '2026-03-10']);

        foreach (['spels', 'coe_auth'] as $category) {
            $report = $this->statistics->report($category, self::FROM, self::TO, null);

            foreach ($report['rows'] as $row) {
                foreach (array_keys($report['columns']) as $metric) {
                    $listed = $this->statistics->details($category, $metric, $row['group'], self::FROM, self::TO, null)->total();

                    $this->assertSame($row[$metric], $listed, "{$category} / {$row['group']} / {$metric}");
                }
            }

            foreach (array_keys($report['columns']) as $metric) {
                $this->assertSame($report['totals'][$metric], $this->statistics->details($category, $metric, null, self::FROM, self::TO, null)->total(), "{$category} total / {$metric}");
            }
        }
    }

    public function test_an_unknown_column_is_not_found(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->statistics->details('spels', 'nonsense', null, self::FROM, self::TO, null);
    }

    // ─── Screens ──────────────────────────────────────────────────────────

    public function test_the_statistics_screen_loads_the_report_for_the_requested_range(): void
    {
        $this->spels(TransactionStatus::Submitted);

        $this->actingAs($this->admin)->get('/admin/statistics?category=spels&from=2026-01-01&to=2026-06-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Statistics/Index')
                ->where('category', 'spels')
                ->where('filters.from', '2026-01-01')
                ->where('report.totals.submitted', 1)
                ->has('offices', 2));
    }

    public function test_bad_input_falls_back_to_safe_defaults(): void
    {
        $this->actingAs($this->admin)->get('/admin/statistics?category=nope&from=garbage&to=2026-06-30&office=abc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('category', 'spels')->where('filters.from', today()->startOfYear()->toDateString())->where('filters.office', null));

        $this->actingAs($this->admin)->get('/admin/statistics?from=2026-06-30&to=2026-01-01')
            ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-01-01')->where('filters.to', '2026-06-30'));
    }

    public function test_the_drill_down_endpoint_lists_records(): void
    {
        $this->spels(TransactionStatus::Received, ['date_received_ro' => '2026-03-07', 'lname' => 'Cruz', 'fname' => 'Ana']);

        $this->actingAs($this->admin)->getJson('/admin/statistics/details?category=spels&metric=pending&group=HGE&from=2026-01-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Cruz, Ana')
            ->assertJsonPath('data.0.office', 'FO-SAMAR');

        $this->actingAs($this->admin)->getJson('/admin/statistics/details?category=spels&metric=bogus&from=2026-01-01&to=2026-06-30')->assertNotFound();
    }

    public function test_the_export_is_a_csv_of_the_table_and_is_audited(): void
    {
        $this->spels(TransactionStatus::Submitted, ['elig_code' => '=EVIL()']);

        $csv = $this->actingAs($this->admin)->get('/admin/statistics/export?category=spels&from=2026-01-01&to=2026-06-30')->assertOk()->streamedContent();

        $this->assertStringContainsString('Eligibility,Filed,Forwarded', $csv);
        $this->assertStringContainsString("'=EVIL()", $csv);
        $this->assertStringContainsString('Total', $csv);
        $this->assertSame(1, AuditLog::where('action', 'statistics.exported')->count());
    }

    public function test_only_administrators_see_statistics(): void
    {
        $this->get('/admin/statistics')->assertRedirect('/login');

        $fo = User::factory()->create(['office_id' => 2]);
        foreach (['/admin/statistics', '/admin/statistics/details?metric=pending', '/admin/statistics/export', '/admin/audit/duplicates'] as $url) {
            $this->actingAs($fo)->get($url)->assertForbidden();
        }
    }
}
