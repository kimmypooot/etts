<?php

namespace App\Services\Spels;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Support\Facades\DB;

/**
 * Retrospective SPELS duplicate sweep: the DETECTIVE counterpart to
 * {@see DuplicateCheck}, the preventive gate.
 *
 * The gate governs new writes and grandfathers everything on file. This answers
 * what that leaves open: which applicants already hold more than one approved
 * record for one eligibility?
 *
 * It is a separate class because the gate's result reaches the unauthenticated
 * kiosk and must stay narrow, while this returns whole records to an
 * authenticated administrator. Keeping them apart means widening this report
 * cannot leak fields to an anonymous caller. Its callers must be admin-gated.
 *
 * Identity is normalised exactly as the gate normalises it. If the two drift,
 * the report and the live gate disagree about what a duplicate is.
 *
 * It scans by design: TRIM(COALESCE(col, '')) makes any index on those columns
 * unusable (about 30 ms over 6.3k rows on the legacy data). Do not add one.
 */
final class DuplicateAudit
{
    /**
     * Every applicant holding more than {@see DuplicateCheck::APPROVED_CAP}
     * approved SPELS records for the same eligibility. Spans all offices
     * deliberately: an office-scoped sweep would miss the duplicate worth
     * catching.
     *
     * The date range filters on date_completed in HAVING, not in WHERE, and
     * matches a group when ANY member is inside it. Filtering rows first would
     * hide a duplicate whose earlier half predates the range. A lone bound is
     * widened to the other end of time rather than ignored.
     *
     * @return array{groupCount: int, recordCount: int, groups: list<array<string, mixed>>}
     */
    public function sweep(?string $from = null, ?string $to = null, ?string $eligCode = null): array
    {
        $from = $this->blankToNull($from);
        $to = $this->blankToNull($to);
        $eligCode = $this->blankToNull($eligCode);

        $approved = TransactionStatus::values(TransactionStatus::spelsApproved());
        $statusPlaceholders = implode(',', array_fill(0, count($approved), '?'));

        $inner = [TransactionType::Spels->value, ...$approved];

        $eligFilter = '';
        if ($eligCode !== null) {
            $eligFilter = " AND TRIM(COALESCE(elig_code, '')) = ?";
            $inner[] = $eligCode;
        }

        $inner[] = DuplicateCheck::APPROVED_CAP;

        $rangeHaving = '';
        if ($from !== null || $to !== null) {
            $rangeHaving = ' AND SUM(date_completed IS NOT NULL AND date_completed BETWEEN ? AND ?) > 0';
            $inner[] = $from ?? '1000-01-01';
            $inner[] = $to ?? '9999-12-31';
        }

        // The type/status predicate is repeated outside so the join does not
        // pull in this identity's non-approved rows, which are not part of the
        // rule. The k_* columns are the identity the SQL grouped on; folding on
        // them (not on a PHP re-trim of the raw columns) keeps PHP and SQL
        // agreeing on accent- and case-insensitive matches.
        $outer = [TransactionType::Spels->value, ...$approved];

        $rows = DB::select("SELECT r.id, r.transaction_status, r.transaction_date, r.date_completed,
                       r.official_receipt,
                       o.name AS office_name, o.code AS office_code,
                       g.k_lname, g.k_fname, g.k_mname, g.k_elig,
                       g.approved_count
                  FROM transactions r
                  JOIN (
                        SELECT TRIM(COALESCE(lname, ''))     AS k_lname,
                               TRIM(COALESCE(fname, ''))     AS k_fname,
                               TRIM(COALESCE(mname, ''))     AS k_mname,
                               TRIM(COALESCE(elig_code, '')) AS k_elig,
                               COUNT(*)                      AS approved_count
                          FROM transactions
                         WHERE transaction_type = ?
                           AND transaction_status IN ({$statusPlaceholders})
                           AND TRIM(COALESCE(lname, ''))     <> ''
                           AND TRIM(COALESCE(fname, ''))     <> ''
                           AND TRIM(COALESCE(elig_code, '')) <> ''
                           {$eligFilter}
                         GROUP BY k_lname, k_fname, k_mname, k_elig
                        HAVING COUNT(*) > ?
                               {$rangeHaving}
                       ) g
                    ON TRIM(COALESCE(r.lname, ''))     = g.k_lname
                   AND TRIM(COALESCE(r.fname, ''))     = g.k_fname
                   AND TRIM(COALESCE(r.mname, ''))     = g.k_mname
                   AND TRIM(COALESCE(r.elig_code, '')) = g.k_elig
                  LEFT JOIN offices o ON r.office_id = o.id
                 WHERE r.transaction_type = ?
                   AND r.transaction_status IN ({$statusPlaceholders})
                 ORDER BY g.approved_count DESC, g.k_lname, g.k_fname,
                          g.k_mname, g.k_elig, r.date_completed, r.id", [...$inner, ...$outer]);

        return $this->fold($rows);
    }

    /**
     * Folds the flat rowset into nested groups in one pass. Safe because the
     * ORDER BY clusters a group together. This is also the projection boundary:
     * only the fields listed here reach the caller.
     *
     * @param  list<object>  $rows
     * @return array{groupCount: int, recordCount: int, groups: list<array<string, mixed>>}
     */
    private function fold(array $rows): array
    {
        $groups = [];
        $key = null;
        $index = -1;

        foreach ($rows as $row) {
            $thisKey = implode("\x1F", [$row->k_lname, $row->k_fname, $row->k_mname, $row->k_elig]);

            if ($thisKey !== $key) {
                $key = $thisKey;
                $index++;
                $groups[$index] = [
                    'lname' => (string) $row->k_lname,
                    'fname' => (string) $row->k_fname,
                    'mname' => (string) $row->k_mname,
                    'eligCode' => (string) $row->k_elig,
                    'approvedCount' => (int) $row->approved_count,
                    'records' => [],
                ];
            }

            $groups[$index]['records'][] = [
                'id' => (int) $row->id,
                'status' => (string) $row->transaction_status,
                'dateCompleted' => $row->date_completed,
                'transactionDate' => $row->transaction_date,
                'officeName' => $row->office_name,
                'officeCode' => $row->office_code,
                'officialReceipt' => $row->official_receipt,
            ];
        }

        return [
            'groupCount' => count($groups),
            'recordCount' => array_sum(array_map(fn (array $group) => count($group['records']), $groups)),
            'groups' => $groups,
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return ($value !== null && trim($value) !== '') ? trim($value) : null;
    }
}
