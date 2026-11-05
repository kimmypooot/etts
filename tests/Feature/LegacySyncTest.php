<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\User;
use App\Services\Legacy\LegacySync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Sync from legacy": previewed, stepwise, administrators only, off unless
 * switched on, and it brings legacy rows in without losing anything.
 */
class LegacySyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['etts.legacy_sync.enabled' => false]); // whatever the local .env says
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->admin = User::factory()->admin()->create(['office_id' => 1, 'username' => 'esd.officer']);

        $schema = Schema::connection('legacy');
        $schema->create('esd_casts_office', fn (Blueprint $t) => [$t->integer('office_id'), $t->string('office_name'), $t->string('office_code')]);
        $schema->create('esd_casts_eligibility_tbl', fn (Blueprint $t) => [$t->integer('eligibility_id'), $t->string('eligibility'), $t->string('elig_code')]);

        // The importer reads only the columns it maps, so the tables can be bare.
        $schema->create('esd_casts_records', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('office_id');
            $t->string('fname');
            $t->string('lname');
            $t->string('transaction_type');
            $t->string('transaction_status');
            $t->string('transaction_date');
            $t->string('elig_code')->nullable();
            $t->string('timestamp')->nullable();
        });
        foreach (['esd_casts_records_deleted', 'esd_casts_transmittals', 'esd_casts_transmittal_items', 'esd_etts_activity_logs'] as $name) {
            $schema->create($name, fn (Blueprint $t) => $t->integer('id'));
        }
        foreach (['esd_casts_issuance_requests', 'esd_casts_issuance_requests_deleted'] as $name) {
            $schema->create($name, fn (Blueprint $t) => [$t->integer('issuance_id'), $t->integer('sets_records_id')]);
        }

        $legacy = DB::connection('legacy');
        $legacy->table('esd_casts_office')->insert([['office_id' => 1, 'office_name' => 'Examination Services Division', 'office_code' => 'ESD'], ['office_id' => 2, 'office_name' => 'CSC Field Office - Samar', 'office_code' => 'FO-SAMAR']]);
        $legacy->table('esd_casts_records')->insert(['id' => 1, 'office_id' => 1, 'fname' => 'Ana', 'lname' => 'Cruz', 'transaction_type' => 'Special Eligibility Application', 'transaction_status' => 'Approved', 'transaction_date' => '2026-01-05 08:00:00', 'timestamp' => '2026-01-06 09:00:00']);
    }

    private function on(): void
    {
        config(['etts.legacy_sync.enabled' => true]);
    }

    /** Runs a whole sync through the same calls the page makes. */
    private function syncThroughTheWeb(): array
    {
        $token = $this->actingAs($this->admin)->postJson('/admin/legacy-sync/begin')->assertOk()->json('token');

        foreach (app(LegacySync::class)->steps() as $step) {
            $this->actingAs($this->admin)->postJson('/admin/legacy-sync/step', ['token' => $token, 'step' => $step['key']])->assertOk();
        }

        return $this->actingAs($this->admin)->postJson('/admin/legacy-sync/finish', ['token' => $token])->assertOk()->json('result');
    }

    // ─── Access ───────────────────────────────────────────────────────────

    public function test_when_off_the_page_says_so_and_every_action_refuses(): void
    {
        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertOk()->assertInertia(fn (Assert $page) => $page->component('LegacySync/Index')->where('enabled', false));

        foreach (['preview', 'begin'] as $action) {
            $this->actingAs($this->admin)->postJson("/admin/legacy-sync/{$action}")->assertStatus(422)->assertJsonPath('message', 'Legacy sync is switched off.');
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_only_administrators_can_reach_any_part_of_it(): void
    {
        $this->on();
        $fo = User::factory()->create(['office_id' => 2]);

        $this->get('/admin/legacy-sync')->assertRedirect('/login');
        $this->actingAs($fo)->get('/admin/legacy-sync')->assertForbidden();

        foreach (['preview', 'begin', 'step', 'finish', 'abort'] as $action) {
            $this->actingAs($fo)->postJson("/admin/legacy-sync/{$action}")->assertForbidden();
        }
    }

    // ─── Preview ──────────────────────────────────────────────────────────

    public function test_the_preview_counts_what_would_change_and_writes_nothing(): void
    {
        $this->on();
        DB::table('transactions')->insert(['id' => 1, 'office_id' => 1, 'fname' => 'Ana', 'lname' => 'EditedHere', 'transaction_type' => 'Special Eligibility Application', 'transaction_status' => 'Approved', 'transaction_date' => '2026-01-05 08:00:00', 'updated_at' => '2026-06-01 00:00:00']);
        DB::connection('legacy')->table('esd_casts_records')->insert([
            ['id' => 2, 'office_id' => 1, 'fname' => 'Ben', 'lname' => 'Lim', 'transaction_type' => 'Special Eligibility Application', 'transaction_status' => 'Approved', 'transaction_date' => '2026-02-05 08:00:00', 'timestamp' => '2026-02-06 09:00:00'],
        ]);

        $rows = collect($this->actingAs($this->admin)->postJson('/admin/legacy-sync/preview')->assertOk()->json('rows'))->keyBy('key');

        $this->assertSame(['legacy' => 2, 'add' => 1, 'update' => 0, 'keep' => 1], $rows['transactions'] ? array_intersect_key($rows['transactions'], array_flip(['legacy', 'add', 'update', 'keep'])) : []);
        $this->assertSame('EditedHere', DB::table('transactions')->where('id', 1)->value('lname'));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(0, AuditLog::where('action', 'legacy.synced')->count());
    }

    // ─── The run ──────────────────────────────────────────────────────────

    public function test_a_run_brings_the_records_in_step_by_step_and_is_audited_and_remembered(): void
    {
        $this->on();

        $result = $this->syncThroughTheWeb();

        $this->assertSame('esd.officer', $result['by']);
        $this->assertSame(1, $result['tables']['transactions']['written']);
        $this->assertDatabaseHas('transactions', ['id' => 1, 'lname' => 'Cruz']);
        $this->assertSame(1, AuditLog::where('action', 'legacy.synced')->count());

        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertInertia(fn (Assert $page) => $page
            ->where('last.by', 'esd.officer')
            ->where('connected', true));
    }

    public function test_an_edit_made_here_survives_a_second_sync(): void
    {
        $this->on();
        $this->syncThroughTheWeb();

        DB::table('transactions')->where('id', 1)->update(['lname' => 'EditedHere', 'updated_at' => '2026-09-01 00:00:00']);

        $second = $this->syncThroughTheWeb();

        $this->assertSame('EditedHere', DB::table('transactions')->where('id', 1)->value('lname'));
        $this->assertSame(1, $second['tables']['transactions']['kept']);
        $this->assertSame(0, $second['tables']['transactions']['written']);
    }

    public function test_a_second_run_cannot_start_while_one_is_running(): void
    {
        $this->on();
        $token = $this->actingAs($this->admin)->postJson('/admin/legacy-sync/begin')->assertOk()->json('token');

        $this->actingAs($this->admin)->postJson('/admin/legacy-sync/begin')->assertStatus(422);

        // Abandoning the first frees it.
        $this->actingAs($this->admin)->postJson('/admin/legacy-sync/abort', ['token' => $token])->assertOk();
        $this->actingAs($this->admin)->postJson('/admin/legacy-sync/begin')->assertOk();
    }

    public function test_a_step_needs_the_token_of_the_current_run(): void
    {
        $this->on();

        $this->actingAs($this->admin)->postJson('/admin/legacy-sync/step', ['token' => 'not-a-run', 'step' => 'transactions'])->assertStatus(422);

        $token = $this->actingAs($this->admin)->postJson('/admin/legacy-sync/begin')->json('token');
        $this->actingAs($this->admin)->postJson('/admin/legacy-sync/step', ['token' => $token, 'step' => 'nonsense'])->assertStatus(422);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_an_unreachable_legacy_database_is_explained_not_a_crash(): void
    {
        $this->on();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/legacy.sqlite', 'prefix' => '']]);
        DB::purge('legacy');

        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertOk()->assertInertia(fn (Assert $page) => $page->where('connected', false));
    }

    // ─── The command ──────────────────────────────────────────────────────

    public function test_the_command_previews_by_default_and_only_writes_with_apply(): void
    {
        $this->on();

        $this->artisan('etts:sync-legacy')->assertSuccessful();
        $this->assertDatabaseCount('transactions', 0);

        $this->artisan('etts:sync-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('transactions', ['id' => 1, 'lname' => 'Cruz']);
    }

    public function test_the_command_refuses_when_sync_is_off(): void
    {
        $this->artisan('etts:sync-legacy', ['--apply' => true])->assertFailed();
        $this->assertDatabaseCount('transactions', 0);
    }
}
