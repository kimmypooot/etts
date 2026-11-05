<?php

namespace App\Console\Commands;

use App\Models\Office;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('etts:cutover-check {--local : Rehearsal on a developer machine: HTTPS, debug and domain checks become warnings}')]
#[Description('Pre-flight before going live: configuration, database, attachments, offices and administrators. Changes nothing.')]
class CutoverCheckCommand extends Command
{
    /** @var list<array{string, string, string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $local = (bool) $this->option('local');

        $this->section('Configuration');
        $this->check('APP_KEY is set', filled(config('app.key')));
        $this->check('Debug is off', ! config('app.debug'), 'APP_DEBUG=true shows stack traces to the public', warnOnly: $local);
        $this->check('Environment is production', app()->environment('production'), 'APP_ENV is "'.app()->environment().'"', warnOnly: $local);
        $this->check('APP_URL uses HTTPS', str_starts_with((string) config('app.url'), 'https://'), (string) config('app.url'), warnOnly: $local);
        $this->check('IMIS sign-in address is set', filled(config('services.imis.sso_url')), 'IMIS_SSO_URL is empty');
        $this->check('IMIS portal address is set', filled(config('services.imis.portal_url')), 'IMIS_PORTAL_URL is empty');
        $this->check('Kiosk host is configured', ! empty(config('etts.kiosk.domains')), 'ETTS_KIOSK_DOMAINS is empty, so the kiosk is served under /kiosk on the staff host', warnOnly: $local);
        $this->check('Trusted proxies are set (behind Cloudflare)', filled(env('TRUSTED_PROXIES')), 'TRUSTED_PROXIES is empty: HTTPS and client addresses may be read wrongly', warnOnly: $local);
        $this->check('Sessions are kept in the database', config('session.driver') === 'database', 'SESSION_DRIVER='.config('session.driver'));
        $this->check('The Executive Dashboard feed is on', filled(config('services.imis.feed_key')), 'IMIS_FEED_KEY is empty (optional)', warnOnly: true);
        $this->check('The front end is built', is_file(public_path('build/manifest.json')), 'run npm run build');
        $this->check('Storage and cache are writable', is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')));

        $this->section('Database');
        try {
            DB::connection()->getPdo();
            $this->check('Database connection', true);
        } catch (\Throwable $e) {
            $this->check('Database connection', false, $e->getMessage());

            return $this->finish();
        }
        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()
            ->diff(DB::table('migrations')->pluck('migration'))->count();
        $this->check('No pending migrations', $pending === 0, "{$pending} migration(s) still to run");
        $this->check('Transactions are imported', DB::table('transactions')->exists(), 'run etts:import-legacy-transactions');
        $this->check('The legacy tables are not the live ones', ! in_array(config('database.connections.mysql.database'), [config('database.connections.legacy.database')], true), 'DB_DATABASE and LEGACY_DB_DATABASE are the same database');

        $this->section('Offices and people');
        $offices = Office::all();
        $unmapped = $offices->filter(fn (Office $o) => blank($o->imis_code));
        $this->check('Every office has an IMIS unit code', $unmapped->isEmpty(), 'missing for: '.$unmapped->pluck('code')->implode(', '));
        $admins = DB::table('users')->where('role', 'admin')->count();
        $this->check('At least two administrators have signed in', $admins >= 2, "{$admins} administrator(s) so far; people appear here after their first sign-in from IMIS", warnOnly: true);
        $noOffice = DB::table('users')->where('role', 'field_office')->whereNull('office_id')->count();
        $this->check('Field office users all have an office', $noOffice === 0, "{$noOffice} field office user(s) without an office cannot see any record");

        $this->section('Attachments');
        $disk = Storage::disk('attachments');
        $referenced = collect(['issuance_requests', 'deleted_issuance_requests'])
            ->flatMap(fn (string $t) => [...DB::table($t)->whereNotNull('attachment_1')->pluck('attachment_1'), ...DB::table($t)->whereNotNull('attachment_2')->pluck('attachment_2')])
            ->map(fn ($n) => trim((string) $n))->filter()->unique();
        $missing = $referenced->reject(fn (string $name) => $disk->exists($name));
        $this->check('Every referenced attachment is on disk', $missing->isEmpty(), $missing->count().' missing (sync uploads/ again, then etts:verify-attachments --list)');
        $this->check('Attachments are not publicly reachable', ! is_dir(public_path('attachments')) && ! is_link(public_path('attachments')), 'public/attachments exists');

        $this->section('Kiosks');
        $this->check('At least one kiosk is paired', DB::table('kiosk_terminals')->whereNull('revoked_at')->exists(), 'pair terminals from Admin, Kiosks after go-live', warnOnly: true);
        $this->check('No unused pairing codes are waiting', ! DB::table('kiosk_pairing_codes')->whereNull('used_at')->where('expires_at', '>', now())->exists(), 'an open code could pair a device', warnOnly: true);

        return $this->finish();
    }

    private function section(string $title): void
    {
        $this->rows[] = ["<options=bold>{$title}</>", '', ''];
    }

    private function check(string $label, bool $ok, string $detail = '', bool $warnOnly = false): void
    {
        if ($ok) {
            $this->rows[] = [$label, '<fg=green>ok</>', ''];

            return;
        }
        if ($warnOnly) {
            $this->rows[] = [$label, '<fg=yellow>warning</>', $detail];

            return;
        }
        $this->failed = true;
        $this->rows[] = [$label, '<fg=red>FAIL</>', $detail];
    }

    private function finish(): int
    {
        $this->table(['Check', 'Result', 'Detail'], $this->rows);

        if ($this->failed) {
            $this->components->error('Not ready to go live. Fix the items marked FAIL.');

            return self::FAILURE;
        }

        $this->components->info('Ready. Warnings are worth reading but do not block the cutover.');

        return self::SUCCESS;
    }
}
