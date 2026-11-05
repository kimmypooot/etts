<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Services\Spels\DuplicateAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('etts:verify-import')]
#[Description('Compare the imported data with the legacy database and report anything that does not match (changes nothing).')]
class VerifyImportCommand extends Command
{
    /** @var list<array{string, string, string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(DuplicateAudit $audit): int
    {
        if (! config('database.connections.legacy.database')) {
            $this->components->error('Set LEGACY_DB_DATABASE in .env to the legacy database.');

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');

        $this->compareCount('Offices', $legacy->table('esd_casts_office')->count(), DB::table('offices')->count());
        $this->compareCount('Transactions', $legacy->table('esd_casts_records')->count(), DB::table('transactions')->count());
        $this->compareCount('Issuance requests', $legacy->table('esd_casts_issuance_requests')->count(), DB::table('issuance_requests')->count());
        $this->compareCount('Deleted transactions', $legacy->table('esd_casts_records_deleted')->count(), DB::table('deleted_transactions')->count());
        $this->compareCount('Deleted issuance requests', $legacy->table('esd_casts_issuance_requests_deleted')->count(), DB::table('deleted_issuance_requests')->count());
        $this->compareCount('Transmittals', $legacy->table('esd_casts_transmittals')->count(), DB::table('transmittals')->count());
        $this->compareCount('Transmittal items', $legacy->table('esd_casts_transmittal_items')->count(), DB::table('transmittal_items')->count());
        $this->compareCount('Activity log rows', $legacy->table('esd_etts_activity_logs')->count(), DB::table('legacy_activity_logs')->count());

        $this->compareCount('Sum of amounts', (int) $legacy->table('esd_casts_records')->sum('amount'), (int) DB::table('transactions')->sum('amount'));
        $this->compareCount('Highest transaction id', (int) $legacy->table('esd_casts_records')->max('id'), (int) DB::table('transactions')->max('id'));

        $this->expectZero('Statuses outside the known set (exact spelling)', $this->notIn('transactions', 'transaction_status', TransactionStatus::values(TransactionStatus::cases())));
        $this->expectZero('Types outside the known set (exact spelling)', $this->notIn('transactions', 'transaction_type', array_map(fn (TransactionType $type) => $type->value, TransactionType::cases())));
        $this->expectZero('Certification-type statuses on SPELS or the reverse', $this->familyMismatches());
        $this->expectZero('Issuance requests without a transaction', DB::table('issuance_requests')->whereNotIn('transaction_id', DB::table('transactions')->select('id'))->count());
        $this->expectZero('Ids both live and archived', DB::table('transactions')->whereIn('id', DB::table('deleted_transactions')->select('id'))->count());
        $this->expectZero('Archived rows without a valid reason code', DB::table('deleted_transactions')->whereNotIn('deletion_reason_code', array_map(fn ($case) => $case->value, \App\Enums\DeletionReason::cases()))->count());
        $this->expectZero('Records whose eligibility code is not in the eligibility list', DB::table('transactions')
            ->whereNotNull('elig_code')
            ->whereNotIn('elig_code', DB::table('eligibilities')->select('code'))
            ->count());

        $this->expectZero('Transmittal items pointing at no transaction, live or archived', DB::table('transmittal_items')->where('is_manual', false)->whereNotIn('transaction_id', DB::table('transactions')->select('id'))->whereNotIn('transaction_id', DB::table('deleted_transactions')->select('id'))->count());
        $this->expectZero('Transmittals with an unknown direction or status', DB::table('transmittals')->where(fn ($q) => $q->whereNotIn('direction', ['ESD_TO_FO', 'FO_TO_RO'])->orWhereNotIn('status', ['Draft', 'Released', 'Transmitted']))->count());

        $duplicates = $audit->sweep()['groupCount'];
        $this->expectZero('SPELS duplicate groups (legacy data had 0)', $duplicates);

        $this->table(['Check', 'Legacy / expected', 'Result'], $this->rows);

        if ($this->failed) {
            $this->components->error('The import does not match the legacy database. Fix the cause and run it again.');

            return self::FAILURE;
        }

        $this->components->info('The import matches the legacy database.');

        return self::SUCCESS;
    }

    private function compareCount(string $label, int $legacy, int $imported): void
    {
        $ok = $legacy === $imported;
        $this->failed = $this->failed || ! $ok;
        $this->rows[] = [$label, (string) $legacy, ($ok ? 'OK ' : 'MISMATCH ').$imported];
    }

    private function expectZero(string $label, int $count): void
    {
        $this->failed = $this->failed || $count !== 0;
        $this->rows[] = [$label, '0', ($count === 0 ? 'OK ' : 'FAIL ').$count];
    }

    /**
     * Rows whose value is not exactly one of `$allowed`. MySQL compares text
     * case-insensitively, so a plain `NOT IN` would pass "completed" for
     * "Completed"; BINARY makes the comparison exact.
     *
     * @param  list<string>  $allowed
     */
    private function notIn(string $table, string $column, array $allowed): int
    {
        $expression = DB::getDriverName() === 'sqlite' ? $column : "BINARY {$column}";

        return DB::table($table)->whereRaw($expression.' not in ('.implode(',', array_fill(0, count($allowed), '?')).')', $allowed)->count();
    }

    private function familyMismatches(): int
    {
        $spels = TransactionType::Spels->value;

        return DB::table('transactions')->where(function ($query) use ($spels) {
            $query->where('transaction_type', $spels)->whereIn('transaction_status', TransactionStatus::values(TransactionStatus::certsTerminal()));
        })->orWhere(function ($query) use ($spels) {
            $query->where('transaction_type', '<>', $spels)->whereIn('transaction_status', TransactionStatus::values(TransactionStatus::spelsTerminal()));
        })->count();
    }
}
