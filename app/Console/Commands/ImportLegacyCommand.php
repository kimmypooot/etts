<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies the legacy reference data (offices, eligibilities) from the read-only
 * `legacy` connection. Idempotent: safe to run again, it updates rows by id.
 * Legacy ids are kept so later imports (transactions) still point at the right
 * rows. Transactions, issuance requests, transmittals, the archive and the
 * activity log are added in Phase 3.
 */
#[Signature('etts:import-legacy')]
#[Description('Import offices and eligibilities from the legacy ETTS database (read only).')]
class ImportLegacyCommand extends Command
{
    /**
     * Codes in use on legacy records but missing from its eligibility table,
     * whose title ESD has confirmed. Any other missing code is only reported.
     *
     * @var array<string, string>
     */
    private const CONFIRMED_MISSING = [
        'CSE-PR' => 'CSE-Preference Rating',
    ];

    public function handle(): int
    {
        if (! config('database.connections.legacy.database')) {
            $this->components->error('Set LEGACY_DB_DATABASE in .env to the legacy database.');

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');

        $offices = $legacy->table('esd_casts_office')->orderBy('office_id')->get();
        $imisCodes = config('etts.imis_office_codes');
        $unmapped = [];

        foreach ($offices as $office) {
            $code = trim($office->office_code);

            if (! isset($imisCodes[$code])) {
                $unmapped[] = $code;
            }

            DB::table('offices')->updateOrInsert(
                ['id' => (int) $office->office_id],
                ['name' => trim($office->office_name), 'code' => $code, 'imis_code' => $imisCodes[$code] ?? null, 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $this->components->info("Offices: {$offices->count()} imported.");

        foreach ($unmapped as $code) {
            $this->components->warn("Office {$code} has no IMIS unit code in config/etts.php (imis_office_codes), so staff at that office will sign in with no office.");
        }

        $eligibilities = $legacy->table('esd_casts_eligibility_tbl')->orderBy('eligibility_id')->get();
        foreach ($eligibilities as $eligibility) {
            DB::table('eligibilities')->updateOrInsert(
                ['id' => (int) $eligibility->eligibility_id],
                ['code' => trim($eligibility->elig_code), 'name' => trim($eligibility->eligibility), 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $this->components->info("Eligibilities: {$eligibilities->count()} imported.");

        $this->reportMissingEligibilities($legacy);

        return self::SUCCESS;
    }

    private function reportMissingEligibilities(\Illuminate\Database\Connection $legacy): void
    {
        $known = DB::table('eligibilities')->pluck('code')->map(fn ($code) => strtoupper($code))->all();

        $used = $legacy->table('esd_casts_records')
            ->selectRaw('trim(elig_code) as code, count(*) as uses')
            ->whereNotNull('elig_code')
            ->groupByRaw('trim(elig_code)')
            ->get();

        foreach ($used as $row) {
            $code = (string) $row->code;

            if ($code === '' || in_array(strtoupper($code), $known, true)) {
                continue;
            }

            if (isset(self::CONFIRMED_MISSING[strtoupper($code)])) {
                DB::table('eligibilities')->insert([
                    'code' => strtoupper($code),
                    'name' => self::CONFIRMED_MISSING[strtoupper($code)],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->components->info("Eligibility {$code} added ({$row->uses} legacy records use it).");

                continue;
            }

            $this->components->warn("Eligibility code '{$code}' is used by {$row->uses} legacy record(s) but is not in the eligibility table. Not imported.");
        }

        $blank = $legacy->table('esd_casts_records')->whereRaw("trim(elig_code) = ''")->count();
        if ($blank > 0) {
            $this->components->warn("{$blank} legacy record(s) have an empty eligibility code. They will be imported with no eligibility.");
        }
    }
}
