<?php

namespace App\Services\Legacy;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Refreshes this system from the legacy database while the legacy app is still
 * the one in use (the weeks before cutover). Three ideas keep it safe:
 *
 *  - It is stepwise: offices and eligibilities, then one table per step. Each
 *    step is short, so a web request never runs long enough to time out, and
 *    the page can show progress. The `etts:sync-legacy` command runs the same
 *    steps in a row.
 *  - It previews first: {@see preview()} counts, with no writes, what a sync
 *    would add, update and leave alone.
 *  - It never loses work done here: the importer's sync mode only writes rows
 *    that are new, or that legacy changed after this system last did.
 *
 * It only ever reads the legacy side. It is switched off by default
 * (`etts.legacy_sync.enabled`) and must stay off once ETTS is the live system:
 * from then on this system is the source of truth and the legacy copy is stale.
 */
class LegacySync
{
    private const LOCK = 'legacy-sync';

    private const LAST_KEY = 'legacy-sync.last';

    private const LOCK_SECONDS = 1800;

    /**
     * step key (= the table here) => [label, legacy table, legacy id column, legacy "last changed" column or null]
     * The order matters: later steps link to rows the earlier ones bring in.
     */
    private const TABLES = [
        'transactions' => ['Transactions', 'esd_casts_records', 'id', 'timestamp'],
        'issuance_requests' => ['Issuance requests', 'esd_casts_issuance_requests', 'issuance_id', 'updated_at'],
        'deleted_transactions' => ['Deleted records', 'esd_casts_records_deleted', 'id', 'timestamp'],
        'deleted_issuance_requests' => ['Deleted issuance requests', 'esd_casts_issuance_requests_deleted', 'issuance_id', 'updated_at'],
        'transmittals' => ['Transmittals', 'esd_casts_transmittals', 'id', null],
        'transmittal_items' => ['Transmittal items', 'esd_casts_transmittal_items', 'id', null],
        'legacy_activity_logs' => ['Activity history', 'esd_etts_activity_logs', 'id', null],
    ];

    public function __construct(private TransactionImporter $importer) {}

    public function enabled(): bool
    {
        return (bool) config('etts.legacy_sync.enabled') && filled(config('database.connections.legacy.database'));
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function steps(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Offices and eligibilities'],
            ...array_map(fn (string $key, array $spec) => ['key' => $key, 'label' => $spec[0]], array_keys(self::TABLES), self::TABLES),
        ];
    }

    /**
     * Whether the legacy database can be reached, and the last run.
     *
     * @return array{connected: bool, error: ?string, last: ?array<string, mixed>}
     */
    public function status(): array
    {
        $error = null;

        if ($this->enabled()) {
            try {
                DB::connection('legacy')->getPdo();
            } catch (Throwable $e) {
                report($e);
                $error = 'The legacy database could not be reached. Check LEGACY_DB_* in the .env file.';
            }
        }

        return ['connected' => $error === null, 'error' => $error, 'last' => Cache::get(self::LAST_KEY)];
    }

    /**
     * What a sync would do, counted without writing anything. An estimate: a
     * legacy row that cannot be linked (and would be skipped) is still counted.
     *
     * @return list<array{key: string, label: string, legacy: ?int, add: ?int, update: ?int, keep: ?int}>
     */
    public function preview(): array
    {
        $this->assertEnabled();

        $legacy = DB::connection('legacy');
        $rows = [];

        foreach (self::TABLES as $key => [$label, $source, $idColumn, $changedColumn]) {
            $row = ['key' => $key, 'label' => $label, 'legacy' => null, 'add' => null, 'update' => null, 'keep' => null];

            try {
                $withChange = $changedColumn !== null && Schema::connection('legacy')->hasColumn($source, $changedColumn);
                $columns = $withChange ? [$idColumn, $changedColumn] : [$idColumn];
                $row['legacy'] = $row['add'] = $row['update'] = $row['keep'] = 0;

                $legacy->table($source)->select($columns)->orderBy($idColumn)->chunk(2000, function ($chunk) use (&$row, $key, $idColumn, $changedColumn, $withChange) {
                    $ids = $chunk->pluck($idColumn)->all();
                    $here = DB::table($key)->whereIn('id', $ids)->pluck($withChange ? 'updated_at' : 'id', 'id')->all();

                    foreach ($chunk as $legacyRow) {
                        $row['legacy']++;
                        $id = $legacyRow->{$idColumn};

                        if (! array_key_exists($id, $here)) {
                            $row['add']++;
                        } elseif ($withChange && ($here[$id] === null || ((string) $legacyRow->{$changedColumn}) > (string) $here[$id])) {
                            $row['update']++;
                        } else {
                            $row['keep']++;
                        }
                    }
                });
            } catch (Throwable $e) {
                report($e);
                $row = ['key' => $key, 'label' => $label, 'legacy' => null, 'add' => null, 'update' => null, 'keep' => null];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Starts a run: takes the one-at-a-time lock and returns the token the
     * steps must present.
     *
     * @throws RuntimeException when sync is off or another run holds the lock
     */
    public function begin(): string
    {
        $this->assertEnabled();

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('A sync is already running. Try again in a few minutes.');
        }

        Cache::put($this->runKey($lock->owner()), ['tables' => [], 'reference' => [], 'warnings' => []], self::LOCK_SECONDS);

        return $lock->owner();
    }

    /**
     * Runs one step of the run `$token` started.
     *
     * @return array{key: string, read: int, written: int, skipped: int, kept: int, notes: list<string>}
     *
     * @throws RuntimeException for an unknown step or a token that is not the current run's
     */
    public function step(string $token, string $key): array
    {
        $this->assertEnabled();
        $this->assertOwner($token);

        // A large copy outlives the default request limit, and a closed tab must not stop it halfway.
        @set_time_limit(0);
        ignore_user_abort(true);

        $run = Cache::get($this->runKey($token), ['tables' => [], 'reference' => [], 'warnings' => []]);

        if ($key === 'reference') {
            $lines = $this->reference();
            $run['reference'] = $lines;
            Cache::put($this->runKey($token), $run, self::LOCK_SECONDS);

            return ['key' => $key, 'read' => 0, 'written' => 0, 'skipped' => 0, 'kept' => 0, 'notes' => $lines];
        }

        if (! isset(self::TABLES[$key])) {
            throw new RuntimeException('Unknown step.');
        }

        $report = $this->importer->run(sync: true, only: [$key]);
        $table = $report['tables'][$key] ?? ['read' => 0, 'written' => 0, 'skipped' => 0, 'kept' => 0];

        $run['tables'][$key] = $table;
        $run['warnings'] = [...$run['warnings'], ...$report['warnings']];
        Cache::put($this->runKey($token), $run, self::LOCK_SECONDS);

        return ['key' => $key, ...$table, 'notes' => $report['warnings']];
    }

    /**
     * Ends the run: records it (audit log and "last sync") and releases the lock.
     *
     * @return array{at: string, by: ?string, reference: list<string>, tables: array<string, array<string, int>>, warnings: list<string>}
     */
    public function finish(string $token, ?string $by = null): array
    {
        $this->assertOwner($token);

        $run = Cache::get($this->runKey($token), ['tables' => [], 'reference' => [], 'warnings' => []]);
        $result = ['at' => now()->toIso8601String(), 'by' => $by, ...$run];

        Cache::forever(self::LAST_KEY, $result);
        Cache::forget($this->runKey($token));

        $written = array_sum(array_column($run['tables'], 'written'));
        AuditLog::record('legacy.synced', null, after: ['summary' => "Synced from the legacy database: {$written} row(s) written.", 'skipped' => array_sum(array_column($run['tables'], 'skipped'))]);

        Cache::restoreLock(self::LOCK, $token)->release();

        return $result;
    }

    /** Frees the lock of a run that was abandoned part-way (the tab was closed or a step failed). */
    public function abort(string $token): void
    {
        if (Cache::restoreLock(self::LOCK, $token)->isOwnedByCurrentProcess()) {
            Cache::forget($this->runKey($token));
            Cache::restoreLock(self::LOCK, $token)->release();
        }
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Legacy sync is switched off.');
        }
    }

    private function assertOwner(string $token): void
    {
        if ($token === '' || ! Cache::restoreLock(self::LOCK, $token)->isOwnedByCurrentProcess()) {
            throw new RuntimeException('This sync is no longer running. Start it again.');
        }
    }

    private function runKey(string $token): string
    {
        return 'legacy-sync.run.'.$token;
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
}
