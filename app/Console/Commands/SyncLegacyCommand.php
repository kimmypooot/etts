<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacySync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('etts:sync-legacy {--apply : Write the changes. Without it the command only shows what a sync would do}')]
#[Description('Refresh from the legacy database without overwriting edits made here (preview by default). Needs ETTS_LEGACY_SYNC=true.')]
class SyncLegacyCommand extends Command
{
    public function handle(LegacySync $sync): int
    {
        if (! $sync->enabled()) {
            $this->components->error('Legacy sync is off. Set ETTS_LEGACY_SYNC=true and LEGACY_DB_DATABASE in .env (and run config:clear if the config is cached).');

            return self::FAILURE;
        }

        try {
            $this->components->info('Preview: what a sync would do (an estimate; nothing is written).');
            $this->table(
                ['Table', 'In legacy', 'Would add', 'Would update', 'Kept as is'],
                array_map(fn (array $row) => [$row['label'], $row['legacy'] ?? '?', $row['add'] ?? '?', $row['update'] ?? '?', $row['keep'] ?? '?'], $sync->preview()),
            );

            if (! $this->option('apply')) {
                $this->line('Run again with --apply to write these changes.');

                return self::SUCCESS;
            }

            $token = $sync->begin();

            try {
                foreach ($sync->steps() as $step) {
                    $result = $sync->step($token, $step['key']);
                    $this->components->twoColumnDetail($step['label'], $step['key'] === 'reference' ? 'done' : "{$result['written']} written, {$result['kept']} kept, {$result['skipped']} skipped");

                    foreach (array_slice($result['notes'], 0, 10) as $note) {
                        $this->components->warn($note);
                    }
                }

                $final = $sync->finish($token, 'console');
            } catch (\Throwable $e) {
                $sync->abort($token);

                throw $e;
            }

            $this->components->info('Sync finished.');

            return collect($final['tables'])->sum('skipped') > 0 ? self::FAILURE : self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
