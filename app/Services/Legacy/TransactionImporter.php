<?php

namespace App\Services\Legacy;

use Closure;
use App\Enums\TransactionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the legacy transaction data into ETTS v2 from the read-only `legacy`
 * connection: records, issuance requests, the deleted-records archive and the
 * activity trail. Offices and eligibilities must already be imported
 * (`etts:import-legacy`).
 *
 * Idempotent: rows are upserted by their legacy id, and legacy ids are kept, so
 * a re-run refreshes rows instead of duplicating them. A re-run does not remove
 * rows that were deleted from the legacy database since; for the final import
 * at cutover use `fresh`, which empties these tables first.
 *
 * Normalisation, applied to the copy only (the legacy database is never
 * written): identity columns (names, extension, eligibility code) are trimmed,
 * and an empty middle name, extension, sex or eligibility code becomes NULL, so
 * the production NULL-versus-empty split disappears. Every other column is
 * copied as is. The counts of what was changed are in the report.
 */
final class TransactionImporter
{
    private const CHUNK = 500;

    /** Columns shared by `transactions` and `deleted_transactions`; legacy `timestamp` becomes `updated_at`. */
    private const TRANSACTION_COLUMNS = [
        'id', 'office_id', 'fname', 'lname', 'mname', 'extn', 'sex', 'transaction_type', 'transaction_date',
        'transaction_details', 'elig_code', 'official_receipt', 'or_date', 'date_sent_to_ro', 'date_received_ro',
        'transaction_status', 'date_completed', 'remarks', 'document_status', 'doc_release_date', 'amount',
        'mobile', 'email', 'action_officer',
    ];

    private const DELETED_ONLY_COLUMNS = ['deleted_at', 'deleted_by_id', 'deleted_by_name', 'deletion_reason_code', 'deletion_reason_note'];

    private const ISSUANCE_COLUMNS = [
        'current_address', 'sex', 'civil_status', 'date_of_birth', 'place_of_birth', 'employment_status',
        'company_name', 'company_address', 'purpose_of_request', 'used_lastname', 'used_firstname',
        'used_middlename', 'used_extn', 'used_address', 'eligibility', 'date_of_exam_issuance',
        'place_of_exam_issuance', 'book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating',
        'effectivity_date', 'release_date', 'attachment_1', 'attachment_2', 'created_at', 'updated_at',
    ];

    private const TRIMMED = ['fname', 'lname', 'mname', 'extn', 'elig_code'];

    private const EMPTY_TO_NULL = ['mname', 'extn', 'sex', 'elig_code'];

    /** @var array<string, int> */
    private array $normalised = ['trimmed' => 0, 'emptied_to_null' => 0, 'zero_dates_cleared' => 0, 'statuses_canonicalised' => 0, 'sex_expanded' => 0];

    /** Tables that carry `updated_at`: a sync only overwrites a row the legacy side changed more recently. */
    private const NEWER_WINS = ['transactions', 'deleted_transactions', 'issuance_requests', 'deleted_issuance_requests'];

    /** True during a sync (see run()): never overwrite what this system holds. */
    private bool $sync = false;

    /** When set, only these destination tables are copied (a stepped sync runs one at a time). */
    private ?array $only = null;

    /** @var array<string, array{read: int, written: int, skipped: int, kept: int}> */
    private array $tables = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * With `sync`, the run is safe to repeat while the legacy app is still in
     * use: a row is only written when it is new here, or (for tables with
     * `updated_at`) when legacy changed it after this system last did. A row
     * edited here since the last import is kept, and so is any row legacy has
     * not touched. Tables without `updated_at` (transmittals, their items, the
     * activity trail) only gain new rows. Rows deleted in legacy are reported,
     * not removed; the final import at cutover uses `fresh`.
     *
     * @return array{tables: array<string, array{read: int, written: int, skipped: int, kept: int}>, normalised: array<string, int>, warnings: list<string>}
     */
    public function run(bool $fresh = false, bool $sync = false, ?array $only = null): array
    {
        $this->sync = $sync && ! $fresh;
        $this->only = $only;
        $this->tables = [];
        $this->warnings = [];
        $this->normalised = ['trimmed' => 0, 'emptied_to_null' => 0, 'zero_dates_cleared' => 0, 'statuses_canonicalised' => 0, 'sex_expanded' => 0];

        if ($fresh) {
            $this->empty();
        }

        $offices = DB::table('offices')->pluck('id')->flip()->all();

        $this->copy('esd_casts_records', 'transactions', 'id',
            fn (array $row) => $this->transactionRow($row),
            fn (array $row) => isset($offices[(int) $row['office_id']]) ? null : "office {$row['office_id']} does not exist",
        );

        $live = DB::table('transactions')->pluck('id')->flip()->all();

        $this->copy('esd_casts_issuance_requests', 'issuance_requests', 'issuance_id',
            fn (array $row) => $this->issuanceRow($row, 'issuance_id'),
            fn (array $row) => isset($live[(int) $row['sets_records_id']]) ? null : "transaction {$row['sets_records_id']} was not imported",
        );

        $this->copy('esd_casts_records_deleted', 'deleted_transactions', 'id',
            fn (array $row) => $this->transactionRow($row, self::DELETED_ONLY_COLUMNS),
        );

        $this->copy('esd_casts_issuance_requests_deleted', 'deleted_issuance_requests', 'issuance_id',
            fn (array $row) => $this->issuanceRow($row, 'issuance_id', ['deleted_at', 'deleted_by_id']),
        );

        $this->copy('esd_casts_transmittals', 'transmittals', 'id',
            fn (array $row) => $this->transmittalRow($row),
            fn (array $row) => isset($offices[(int) $row['target_office_id']]) && ($row['origin_office_id'] === null || isset($offices[(int) $row['origin_office_id']])) ? null : 'its office does not exist',
        );

        $transmittals = DB::table('transmittals')->pluck('id')->flip()->all();

        $this->copy('esd_casts_transmittal_items', 'transmittal_items', 'id',
            fn (array $row) => $this->transmittalItemRow($row),
            fn (array $row) => isset($transmittals[(int) $row['transmittal_id']]) ? null : "transmittal {$row['transmittal_id']} was not imported",
        );

        $this->copy('esd_etts_activity_logs', 'legacy_activity_logs', 'id',
            fn (array $row) => $this->only($row, ['id', 'user_id', 'record_id', 'username', 'action_type', 'details', 'created_at']),
            null,
            1000,
        );

        $orphans = ($this->only !== null && ! in_array('legacy_activity_logs', $this->only, true)) ? 0 : DB::table('legacy_activity_logs')
            ->whereNotNull('record_id')
            ->whereNotIn('record_id', DB::table('transactions')->select('id'))
            ->whereNotIn('record_id', DB::table('deleted_transactions')->select('id'))
            ->count();

        if ($orphans > 0) {
            $this->warnings[] = "{$orphans} activity log row(s) point at a record that is in neither the live table nor the archive. Imported as history, with no link.";
        }

        if ($this->sync && ($this->only === null || in_array('deleted_transactions', $this->only, true))) {
            $stillLive = DB::table('transactions')->whereIn('id', DB::table('deleted_transactions')->select('id'))->count();

            if ($stillLive > 0) {
                $this->warnings[] = "{$stillLive} record(s) were deleted in the legacy app but are still live here. A sync does not remove records; the final import at cutover (--fresh) does.";
            }
        }

        return ['tables' => $this->tables, 'normalised' => $this->normalised, 'warnings' => $this->warnings];
    }

    private function empty(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['transmittal_items', 'transmittals', 'legacy_activity_logs', 'deleted_issuance_requests', 'deleted_transactions', 'issuance_requests', 'transactions'] as $table) {
            DB::table($table)->delete();
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $map
     * @param  (Closure(array<string, mixed>): ?string)|null  $skipReason  Returns why a row cannot be imported, or null.
     */
    private function copy(string $source, string $destination, string $orderBy, Closure $map, ?Closure $skipReason = null, int $chunk = self::CHUNK): void
    {
        if ($this->only !== null && ! in_array($destination, $this->only, true)) {
            return;
        }

        $report = ['read' => 0, 'written' => 0, 'skipped' => 0, 'kept' => 0];

        DB::connection('legacy')->table($source)->orderBy($orderBy)->chunk($chunk, function ($rows) use ($destination, $map, $skipReason, &$report, $source) {
            $batch = [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $report['read']++;

                $reason = $skipReason ? $skipReason($row) : null;
                if ($reason !== null) {
                    $report['skipped']++;
                    $this->warnings[] = "{$source} #".($row['id'] ?? $row['issuance_id'] ?? '?').": skipped, {$reason}.";

                    continue;
                }

                $batch[] = $map($row);
            }

            if ($this->sync && $batch !== []) {
                $before = count($batch);
                $batch = $this->changedOnly($destination, $batch);
                $report['kept'] += $before - count($batch);
            }

            if ($batch !== []) {
                DB::table($destination)->upsert($batch, ['id'], array_values(array_diff(array_keys($batch[0]), ['id'])));
                $report['written'] += count($batch);
            }
        });

        $this->tables[$destination] = $report;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $extra
     * @return array<string, mixed>
     */
    private function transactionRow(array $row, array $extra = []): array
    {
        $out = $this->only($row, [...self::TRANSACTION_COLUMNS, ...$extra]);

        if (array_key_exists('timestamp', $row)) {
            $out['updated_at'] = $row['timestamp'];
        }

        $out = $this->nullZeroDates($out);
        $out = $this->canonicalStatus($out);
        $out = $this->canonicalSex($out);

        // transaction_date is required. The legacy data holds a few records with
        // the zero date; use the record's completion date, else the day it was
        // last updated, and say so in the report so ESD can correct it.
        if (array_key_exists('transaction_date', $out) && $out['transaction_date'] === null) {
            $source = $out['date_completed'] ?? null ? 'date_completed' : 'updated_at';
            $out['transaction_date'] = ($out['date_completed'] ?? null) ? $out['date_completed'].' 00:00:00' : ($out['updated_at'] ?? now()->toDateTimeString());
            $this->warnings[] = "Record #{$out['id']} had no valid transaction date; set to {$out['transaction_date']} from {$source}.";
        }

        foreach (self::TRIMMED as $column) {
            if (isset($out[$column]) && is_string($out[$column]) && $out[$column] !== trim($out[$column])) {
                $out[$column] = trim($out[$column]);
                $this->normalised['trimmed']++;
            }
        }

        foreach (self::EMPTY_TO_NULL as $column) {
            if (array_key_exists($column, $out) && is_string($out[$column]) && trim($out[$column]) === '') {
                $out[$column] = null;
                $this->normalised['emptied_to_null']++;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function transmittalRow(array $row): array
    {
        $out = $this->only($row, ['id', 'transmittal_no', 'direction', 'target_office_id', 'origin_office_id', 'status', 'created_at', 'remarks']);
        $out['created_by_imis_id'] = $row['created_by'] ?? null;
        $out['remarks'] = (string) ($out['remarks'] ?? '');

        return $this->nullZeroDates($out);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function transmittalItemRow(array $row): array
    {
        $out = $this->only($row, ['id', 'transmittal_id', 'is_manual', 'manual_name', 'manual_description', 'manual_doc_type', 'remarks']);
        $out['transaction_id'] = $row['record_id'] ?? null;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $extra
     * @return array<string, mixed>
     */
    private function issuanceRow(array $row, string $idColumn, array $extra = []): array
    {
        $out = $this->nullZeroDates($this->only($row, [...self::ISSUANCE_COLUMNS, ...$extra]));
        $out['id'] = $row[$idColumn];
        $out['transaction_id'] = $row['sets_records_id'];

        return $out;
    }

    /**
     * The legacy form stored the sex as "M" or "F" in some records and as
     * "Male" or "Female" in others. The copy uses the long form throughout.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function canonicalSex(array $row): array
    {
        $long = ['M' => 'Male', 'F' => 'Female'];

        if (isset($row['sex']) && is_string($row['sex']) && isset($long[strtoupper(trim($row['sex']))])) {
            $row['sex'] = $long[strtoupper(trim($row['sex']))];
            $this->normalised['sex_expanded']++;
        }

        return $row;
    }

    /**
     * The legacy column is case-insensitive, and the legacy revert tool wrote
     * "completed" in lower case, so production holds a few statuses spelt
     * differently from the canonical ones. Store the canonical spelling; a
     * status that matches no case is left as it is and reported.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function canonicalStatus(array $row): array
    {
        if (! isset($row['transaction_status']) || ! is_string($row['transaction_status'])) {
            return $row;
        }

        $status = trim($row['transaction_status']);

        foreach (TransactionStatus::cases() as $case) {
            if (strcasecmp($case->value, $status) === 0) {
                if ($case->value !== $row['transaction_status']) {
                    $row['transaction_status'] = $case->value;
                    $this->normalised['statuses_canonicalised']++;
                }

                return $row;
            }
        }

        $this->warnings[] = "Record #{$row['id']} has the unknown status '{$status}'. Copied as it is.";

        return $row;
    }

    /**
     * MySQL zero dates ("0000-00-00") are not dates; the copy stores NULL.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function nullZeroDates(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && str_starts_with($value, '0000-00-00')) {
                $row[$column] = null;
                $this->normalised['zero_dates_cleared']++;
            }
        }

        return $row;
    }

    /**
     * Sync only: drops the rows that must not be written. A row new to this
     * system is always written. For a table with `updated_at`, an existing row
     * is rewritten only when legacy's copy is strictly newer (a row edited here
     * has a later `updated_at` than legacy's `timestamp`, so it is kept). For
     * any other table an existing row is kept as it is.
     *
     * @param  list<array<string, mixed>>  $batch
     * @return list<array<string, mixed>>
     */
    private function changedOnly(string $destination, array $batch): array
    {
        $ids = array_column($batch, 'id');

        if (! in_array($destination, self::NEWER_WINS, true)) {
            $existing = DB::table($destination)->whereIn('id', $ids)->pluck('id')->flip()->all();

            return array_values(array_filter($batch, fn (array $row) => ! isset($existing[$row['id']])));
        }

        $existing = DB::table($destination)->whereIn('id', $ids)->pluck('updated_at', 'id')->all();

        return array_values(array_filter($batch, function (array $row) use ($existing) {
            if (! array_key_exists($row['id'], $existing)) {
                return true;
            }

            $here = $existing[$row['id']];
            $legacy = $row['updated_at'] ?? null;

            return $here === null || ($legacy !== null && (string) $legacy > (string) $here);
        }));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function only(array $row, array $columns): array
    {
        return array_intersect_key($row, array_flip($columns));
    }
}
