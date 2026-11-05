<?php

namespace Tests\Feature\Spels;

use App\Enums\TransactionStatus;
use App\Models\Office;
use App\Models\Transaction;
use App\Services\Spels\DuplicateAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateAuditTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->audit = new DuplicateAudit;
    }

    private function approved(string $lname, string $fname, ?string $mname, string $elig, string $completed, array $extra = []): Transaction
    {
        return Transaction::factory()->approved($completed)->forApplicant($lname, $fname, $mname, $elig)->create($extra);
    }

    public function test_it_finds_an_applicant_approved_twice_for_one_eligibility(): void
    {
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2024-01-10');
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2025-03-05', ['office_id' => 2, 'transaction_status' => TransactionStatus::ApprovedReleased]);
        $this->approved('Cruz', 'Ana', 'Reyes', 'BOE', '2025-03-05');
        $this->approved('Lim', 'Ben', null, 'HGE', '2025-03-05');

        $report = $this->audit->sweep();

        $this->assertSame(1, $report['groupCount']);
        $this->assertSame(2, $report['recordCount']);
        $this->assertSame('Cruz', $report['groups'][0]['lname']);
        $this->assertSame(2, $report['groups'][0]['approvedCount']);
        $this->assertSame(['ESD', 'FO-SAMAR'], array_column($report['groups'][0]['records'], 'officeCode'));
    }

    public function test_null_and_empty_middle_names_fold_into_one_group(): void
    {
        $this->approved('Cruz', 'Ana', null, 'HGE', '2024-01-10');
        $this->approved('Cruz', 'Ana', '', 'HGE', '2025-03-05');

        $this->assertSame(1, $this->audit->sweep()['groupCount']);
    }

    public function test_no_duplicates_gives_an_empty_report(): void
    {
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2024-01-10');

        $this->assertSame(['groupCount' => 0, 'recordCount' => 0, 'groups' => []], $this->audit->sweep());
    }

    public function test_pending_records_do_not_make_a_duplicate(): void
    {
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2024-01-10');
        Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create();

        $this->assertSame(0, $this->audit->sweep()['groupCount']);
    }

    public function test_the_date_range_matches_a_group_when_any_member_is_inside_it(): void
    {
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2023-01-10');
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2025-03-05');

        // Only the second half is inside 2025, yet the group must still show
        // both records: filtering rows first would hide the older approval.
        $report = $this->audit->sweep('2025-01-01', '2025-12-31');

        $this->assertSame(1, $report['groupCount']);
        $this->assertSame(2, $report['recordCount']);

        $this->assertSame(0, $this->audit->sweep('2026-01-01', '2026-12-31')['groupCount']);
    }

    public function test_a_lone_bound_is_widened_not_ignored(): void
    {
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2023-01-10');
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2023-06-10');

        $this->assertSame(1, $this->audit->sweep('2023-01-01')['groupCount']);
        $this->assertSame(0, $this->audit->sweep('2024-01-01')['groupCount']);
        $this->assertSame(1, $this->audit->sweep(null, '2023-12-31')['groupCount']);
    }

    public function test_it_can_be_limited_to_one_eligibility(): void
    {
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2024-01-10');
        $this->approved('Cruz', 'Ana', 'Reyes', 'HGE', '2025-01-10');
        $this->approved('Lim', 'Ben', null, 'BOE', '2024-01-10');
        $this->approved('Lim', 'Ben', null, 'BOE', '2025-01-10');

        $this->assertSame(2, $this->audit->sweep()['groupCount']);
        $this->assertSame('BOE', $this->audit->sweep(null, null, 'BOE')['groups'][0]['eligCode']);
        $this->assertSame(1, $this->audit->sweep(null, null, 'BOE')['groupCount']);
    }
}
