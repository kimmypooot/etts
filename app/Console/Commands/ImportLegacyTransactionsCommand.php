<?php

namespace App\Console\Commands;

use App\Services\Legacy\TransactionImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('etts:import-legacy-transactions {--fresh : Empty the transaction tables first (use for the final import at cutover)} {--sync : Only add new rows and rows legacy changed after this system did; never overwrite newer edits made here}')]
#[Description('Import transactions, issuance requests, the deleted-records archive and the activity trail from the legacy database (read only).')]
class ImportLegacyTransactionsCommand extends Command
{
    public function handle(TransactionImporter $importer): int
    {
        if (! config('database.connections.legacy.database')) {
            $this->components->error('Set LEGACY_DB_DATABASE in .env to the legacy database.');

            return self::FAILURE;
        }

        if (DB::table('offices')->count() === 0) {
            $this->components->error('Import the offices first: php artisan etts:import-legacy');

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! $this->confirmFresh()) {
            return self::FAILURE;
        }

        $report = $importer->run((bool) $this->option('fresh'), (bool) $this->option('sync'));

        $this->table(
            ['Table', 'Read', 'Written', 'Kept', 'Skipped'],
            collect($report['tables'])->map(fn (array $row, string $table) => [$table, $row['read'], $row['written'], $row['kept'], $row['skipped']])->values()->all(),
        );

        $this->components->info("Normalised: {$report['normalised']['trimmed']} value(s) trimmed, {$report['normalised']['emptied_to_null']} empty value(s) set to NULL, {$report['normalised']['zero_dates_cleared']} zero date(s) cleared, {$report['normalised']['statuses_canonicalised']} status spelling(s) corrected, {$report['normalised']['sex_expanded']} sex value(s) written in full.");

        foreach (array_slice($report['warnings'], 0, 25) as $warning) {
            $this->components->warn($warning);
        }
        if (count($report['warnings']) > 25) {
            $this->components->warn((count($report['warnings']) - 25).' more warning(s) not shown.');
        }

        $this->line('Next: php artisan etts:verify-import');

        return collect($report['tables'])->sum('skipped') > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function confirmFresh(): bool
    {
        if ($this->input->isInteractive()) {
            return $this->components->confirm('--fresh empties the transaction tables in this database before importing. Continue?');
        }

        return true;
    }
}
