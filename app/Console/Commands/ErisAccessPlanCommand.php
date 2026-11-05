<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Office;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('etts:eris-access-plan {--csv= : Also write the plan to this CSV file}')]
#[Description('Dry-run: who holds ERIS access in IMIS, and what ETTS access and office each would get. Reads only; grants nothing.')]
class ErisAccessPlanCommand extends Command
{
    public function handle(): int
    {
        if (! config('database.connections.imis.database')) {
            $this->components->error('Set IMIS_DB_DATABASE in .env to the IMIS database.');

            return self::FAILURE;
        }

        $imis = DB::connection('imis');
        $holders = $imis->table('application_access as aa')
            ->join('applications as app', 'app.id', '=', 'aa.application_id')
            ->join('application_roles as r', 'r.id', '=', 'aa.application_role_id')
            ->join('users as u', 'u.id', '=', 'aa.user_id')
            ->leftJoin('organizational_units as ou', 'ou.id', '=', 'u.organizational_unit_id')
            ->where('app.short_name', 'ERIS')
            ->orderBy('r.code')->orderBy('u.name')
            ->get(['u.id', 'u.username', 'u.name', 'u.status', 'r.code as eris_role', 'ou.code as unit_code', 'ou.name as unit_name']);

        $already = $imis->table('application_access as aa')
            ->join('applications as app', 'app.id', '=', 'aa.application_id')
            ->join('application_roles as r', 'r.id', '=', 'aa.application_role_id')
            ->where('app.short_name', 'ETTS')
            ->pluck('r.code', 'aa.user_id');

        $plan = $holders->map(function ($h) use ($already) {
            $role = $h->eris_role === 'admin' ? UserRole::Admin : UserRole::FieldOffice;
            $office = Office::forImisCode($h->unit_code);
            $notes = [];

            if ($h->status !== 'active') {
                $notes[] = 'IMIS account is inactive: do not grant';
            }
            if ($role === UserRole::FieldOffice) {
                if (blank($h->unit_code)) {
                    $notes[] = 'no organisational unit in IMIS: would see nothing';
                } elseif (! $office) {
                    $notes[] = "unit {$h->unit_code} matches no ETTS office: would see nothing";
                }
            } elseif ($office && ! $office->isEsd() && $office->code !== 'PALD') {
                $notes[] = "admin in a field office ({$office->code}): ETTS admin sees every office, confirm";
            }
            if ($role === UserRole::FieldOffice && $office?->isEsd()) {
                $notes[] = 'ESD staff with the User role: ETTS has only admin for ESD, confirm';
            }
            if (isset($already[$h->id])) {
                $notes[] = "already has ETTS {$already[$h->id]}";
            }

            return [
                'username' => $h->username,
                'name' => $h->name,
                'eris_role' => $h->eris_role,
                'unit' => $h->unit_code ?: '-',
                'office' => $office?->code ?? '-',
                'etts_role' => $role->value,
                'ready' => isset($already[$h->id]) ? 'done' : ($notes === [] ? 'yes' : 'review'),
                'notes' => implode('; ', $notes),
            ];
        });

        $this->table(['Username', 'Name', 'ERIS', 'IMIS unit', 'ETTS office', 'ETTS role', 'Status', 'Notes'], $plan->map(fn ($r) => array_values($r))->all());
        $this->components->info(sprintf(
            '%d holder(s): %d ready, %d need review, %d already granted. Nothing was changed.',
            $plan->count(), $plan->where('ready', 'yes')->count(), $plan->where('ready', 'review')->count(), $plan->where('ready', 'done')->count(),
        ));

        if ($path = $this->option('csv')) {
            $f = fopen($path, 'w');
            fputcsv($f, array_keys($plan->first() ?? []));
            foreach ($plan as $row) {
                fputcsv($f, $row);
            }
            fclose($f);
            $this->components->info("Written to {$path}");
        }

        return self::SUCCESS;
    }
}
