<?php

namespace App\Services\Spels;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Support\Facades\DB;

/**
 * The SPELS duplicate-eligibility rule: the PREVENTIVE gate.
 *
 * An applicant may hold at most one approved record per eligibility. A second
 * application for an eligibility the same person already holds is refused.
 * Different eligibilities are unrestricted. "Approved" means both
 * {@see TransactionStatus::spelsApproved()} statuses; checking only Approved
 * would miss every released record.
 *
 * Matching contract. Identity is (lname, fname, mname, elig_code), compared
 * after TRIM(COALESCE(x, '')) on both sides, because production data has:
 *  - mname inconsistently NULL vs '' (a plain `mname = ?` never matches NULL);
 *  - names with stray padding;
 *  - a collation that is already case- and accent-insensitive (the legacy
 *    latin1_swedish_ci, kept as utf8mb4_unicode_ci), so never wrap the columns
 *    in UPPER()/LOWER();
 *  - rows with an empty elig_code, which is not an identity: the check does
 *    nothing when no eligibility (or no name) is supplied.
 *
 * It spans ALL offices on purpose (an applicant may have filed through another
 * field office), so it queries the table directly instead of through a
 * model that would apply office scoping. The price is that a caller learns of
 * a record outside its office, hence the narrow {@see DuplicateCheckResult}.
 * The retrospective sweep over existing data is {@see DuplicateAudit}, kept in
 * a separate class so widening the audit report can never leak fields here.
 *
 * Deleted records (the archive) are not consulted: a deleted application must
 * not consume the applicant's slot. Existing data is grandfathered: this gate
 * governs new writes only.
 */
final class DuplicateCheck
{
    /** Maximum approved records one applicant may hold per eligibility. */
    public const APPROVED_CAP = 1;

    /**
     * Prior approved SPELS records for this applicant and eligibility.
     *
     * @param  int|null  $excludeId  The record being edited, excluded from its own check.
     */
    public function check(?string $lname, ?string $fname, ?string $mname, ?string $eligCode, ?int $excludeId = null): DuplicateCheckResult
    {
        $lname = trim((string) $lname);
        $fname = trim((string) $fname);
        $mname = trim((string) $mname);
        $eligCode = trim((string) $eligCode);

        if ($eligCode === '' || $lname === '' || $fname === '') {
            return DuplicateCheckResult::none($eligCode, $lname, $fname);
        }

        $records = DB::table('transactions')
            ->where('transaction_type', TransactionType::Spels->value)
            ->whereIn('transaction_status', TransactionStatus::values(TransactionStatus::spelsApproved()))
            ->whereRaw("TRIM(COALESCE(lname, '')) = ?", [$lname])
            ->whereRaw("TRIM(COALESCE(fname, '')) = ?", [$fname])
            ->whereRaw("TRIM(COALESCE(mname, '')) = ?", [$mname])
            ->whereRaw("TRIM(COALESCE(elig_code, '')) = ?", [$eligCode])
            ->when($excludeId !== null, fn ($query) => $query->where('id', '<>', $excludeId))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get(['id', 'transaction_status', 'transaction_date', 'date_completed', 'office_id'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'transaction_status' => (string) $row->transaction_status,
                'transaction_date' => $row->transaction_date,
                'date_completed' => $row->date_completed,
                'office_id' => (int) $row->office_id,
            ])
            ->all();

        return new DuplicateCheckResult(
            blocked: count($records) >= self::APPROVED_CAP,
            approvedCount: count($records),
            cap: self::APPROVED_CAP,
            records: $records,
            eligCode: $eligCode,
            lname: $lname,
            fname: $fname,
        );
    }

    /**
     * Guard for the approval TRANSITION, the moment a record becomes approved.
     *
     * check() alone is not enough: a SPELS record normally reaches Approved
     * through a status action that touches neither the name nor the
     * eligibility, so without this a second application filed while the first
     * was pending could simply be approved. Does nothing when the target status
     * is not an approved one, the record is not SPELS, or the record is missing.
     *
     * Call it inside the transaction, after locking the row, so a concurrent
     * approval of a rival record cannot slip between the check and the write.
     */
    public function blocksApproval(int $recordId, TransactionStatus|string|null $newStatus): DuplicateCheckResult
    {
        $status = $newStatus instanceof TransactionStatus ? $newStatus : TransactionStatus::tryFrom((string) $newStatus);

        if ($status === null || ! $status->isSpelsApproved()) {
            return DuplicateCheckResult::none();
        }

        $record = DB::table('transactions')->where('id', $recordId)->first(['lname', 'fname', 'mname', 'elig_code', 'transaction_type']);

        if ($record === null || $record->transaction_type !== TransactionType::Spels->value) {
            return DuplicateCheckResult::none();
        }

        return $this->check($record->lname, $record->fname, $record->mname, $record->elig_code, $recordId);
    }

    /**
     * The formal refusal notice, shared by ESD, FO and the kiosk so all three
     * state the rule identically. Cites each existing approval's status and
     * completion date (`date_completed` is filled on every approved SPELS
     * record; `doc_release_date` is mostly empty and must not be used here).
     */
    public function message(DuplicateCheckResult $result): string
    {
        $who = ($result->lname !== '' && $result->fname !== '')
            ? $result->lname.', '.$result->fname
            : 'this applicant';

        $cited = [];
        foreach ($result->records as $record) {
            $on = $this->formatDate($record['date_completed']);
            $cited[] = $on === null ? $record['transaction_status'] : $record['transaction_status'].' on '.$on;
        }

        $notice = 'An approved application for '.$who.' under the eligibility '.$result->eligCode.' is already on record';
        $notice .= $cited !== [] ? ' ('.implode('; ', $cited).').' : '.';

        return $notice.' You may select another transaction if you wish to request the issuance of your Certificate of Eligibility (COE).';
    }

    /** Formats a stored date as e.g. "15 April 2025"; null when unusable. */
    private function formatDate(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? null : date('j F Y', $timestamp);
    }
}
