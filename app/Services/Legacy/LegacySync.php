<?php

namespace App\Services\Legacy;

use App\Models\AuditLog;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Refreshes this system from the legacy database while the legacy app is still
 * the one in use (the weeks before cutover): reference data first (offices,
 * eligibilities), then the transactions and everything that hangs off them, in
 * the importer's safe `sync` mode, which never overwrites what was edited here.
 *
 * It only ever reads the legacy side. It is switched off by default
 * (`etts.legacy_sync.enabled`) and must stay off once ETTS is the live system:
 * from then on this system is the source of truth and the legacy copy is stale.
 */
class LegacySync
{
    private const LOCK = 'legacy-sync';

    private const LAST_KEY = 'legacy-sync.last';

    /** Legacy table => the table here it feeds, for the status comparison. */
    private const COMPARE = [
        'Transactions' => ['esd_casts_records', 'transactions'],
        'Deleted records' => ['esd_casts_records_deleted', 'deleted_transactions'],
        'Transmittals' => ['esd_casts_transmittals', 'transmittals'],
    ];

    public function __construct(private TransactionImporter $importer) {}

    public function enabled(): bool
    {
        return (bool) config('etts.legacy_sync.enabled') && filled(config('database.connections.legacy.database'));
    }

    /**
     * What the page shows before anything is run: whether the legacy database
     * can be reached, how its row counts compare with this system's, and the
     * last run.
     *
     * @return array{connected: bool, error: ?string, counts: list<array{label: string, legacy: ?int, here: int}>, last: ?array<string, mixed>}
     */
    public function status(): array
    {
        $counts = [];
        $error = null;

        try {
            $legacy = DB::connection('legacy');
            $legacy->getPdo();

            foreach (self::COMPARE as $label => [$from, $to]) {
                $counts[] = ['label' => $label, 'legacy' => $legacy->table($from)->count(), 'here' => DB::table($to)->count()];
            }
        } catch (Throwable $e) {
            report($e);
            $error = 'The legacy database could not be reached. Check LEGACY_DB_* in the .env file.';
            $counts = array_map(fn (string $label, array $pair) => ['label' => $label, 'legacy' => null, 'here' => DB::table($pair[1])->count()], array_keys(self::COMPARE), array_values(self::COMPARE));
        }

        return ['connected' => $error === null, 'error' => $error, 'counts' => $counts, 'last' => Cache::get(self::LAST_KEY)];
    }

    /**
     * @return array{at: string, by: ?string, reference: list<string>, tables: array<string, array{read: int, written: int, skipped: int, kept: int}>, warnings: list<string>}
     *
     * @throws RuntimeException when sync is off, another run is under way, or the legacy database is unusable
     */
    public function run(?string $by = null): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Legacy sync is switched off.');
        }

        $lock = Cache::lock(self::LOCK, 900);

        if (! $lock->get()) {
            throw new RuntimeException('A sync is already running. Try again in a few minutes.');
        }

        try {
            // A large copy outlives the default request limit, and a closed tab must not stop it halfway.
            @set_time_limit(0);
            ignore_user_abort(true);

            $reference = $this->reference();
            $report = $this->importer->run(sync: true);

            $result = [
                'at' => now()->toIso8601String(),
                'by' => $by,
                'reference' => $reference,
                'tables' => $report['tables'],
                'warnings' => $report['warnings'],
            ];

            Cache::forever(self::LAST_KEY, $result);

            $written = array_sum(array_column($report['tables'], 'written'));
            AuditLog::record('legacy.synced', null, after: ['summary' => "Synced from the legacy database: {$written} row(s) written.", 'skipped' => array_sum(array_column($report['tables'], 'skipped'))]);

            return $result;
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Offices and eligibilities, through the same command used for the first
     * import (it updates by id, so repeating it is safe).
     *
     * @return list<string>
     */
    private function reference(): array
    {
        $output = new BufferedOutput;

        if (Artisan::call('etts:import-legacy', [], $output) !== 0) {
            throw new RuntimeException('The offices and eligibilities could not be read from the legacy database.');
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output->fetch())), fn (string $line) => $line !== ''));
    }

    private function release(Lock $lock): void
    {
        $lock->release();
    }
}
