<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The "Sync from legacy" screen: off unless switched on, administrators only,
 * and it brings legacy rows in without losing anything.
 */
class LegacySyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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
        foreach (['esd_casts_records_deleted', 'esd_casts_issuance_requests_deleted', 'esd_casts_transmittals', 'esd_casts_transmittal_items', 'esd_etts_activity_logs'] as $name) {
            $schema->create($name, fn (Blueprint $t) => $t->integer('id'));
        }
        $schema->create('esd_casts_issuance_requests', fn (Blueprint $t) => [$t->integer('issuance_id'), $t->integer('sets_records_id')]);
        $schema->table('esd_casts_issuance_requests_deleted', fn (Blueprint $t) => $t->integer('issuance_id')->nullable());

        $legacy = DB::connection('legacy');
        $legacy->table('esd_casts_office')->insert([['office_id' => 1, 'office_name' => 'Examination Services Division', 'office_code' => 'ESD'], ['office_id' => 2, 'office_name' => 'CSC Field Office - Samar', 'office_code' => 'FO-SAMAR']]);
        $legacy->table('esd_casts_records')->insert(['id' => 1, 'office_id' => 1, 'fname' => 'Ana', 'lname' => 'Cruz', 'transaction_type' => 'Special Eligibility Application', 'transaction_status' => 'Approved', 'transaction_date' => '2026-01-05 08:00:00', 'timestamp' => '2026-01-06 09:00:00']);
    }

    private function on(): void
    {
        config(['etts.legacy_sync.enabled' => true]);
    }

    public function test_it_is_off_by_default_even_for_an_administrator(): void
    {
        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/legacy-sync')->assertForbidden();
    }

    public function test_only_administrators_can_use_it_even_when_it_is_on(): void
    {
        $this->on();

        $this->get('/admin/legacy-sync')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['office_id' => 2]))->get('/admin/legacy-sync')->assertForbidden();
        $this->actingAs(User::factory()->create(['office_id' => 2]))->post('/admin/legacy-sync')->assertForbidden();
    }

    public function test_the_page_compares_the_two_databases(): void
    {
        $this->on();

        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('LegacySync/Index')
            ->where('connected', true)
            ->where('counts.0', ['label' => 'Transactions', 'legacy' => 1, 'here' => 0])
            ->where('last', null));
    }

    public function test_running_it_brings_the_records_in_and_is_audited_and_remembered(): void
    {
        $this->on();

        $this->actingAs($this->admin)->post('/admin/legacy-sync')->assertRedirect('/admin/legacy-sync');

        $this->assertDatabaseHas('transactions', ['id' => 1, 'lname' => 'Cruz']);
        $this->assertSame(1, AuditLog::where('action', 'legacy.synced')->count());

        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertInertia(fn (Assert $page) => $page
            ->where('last.by', 'esd.officer')
            ->where('last.tables.transactions.written', 1)
            ->where('counts.0.here', 1));
    }

    public function test_an_edit_made_here_survives_a_second_sync(): void
    {
        $this->on();
        $this->actingAs($this->admin)->post('/admin/legacy-sync');

        DB::table('transactions')->where('id', 1)->update(['lname' => 'EditedHere', 'updated_at' => '2026-09-01 00:00:00']);

        $this->actingAs($this->admin)->post('/admin/legacy-sync');

        $this->assertSame('EditedHere', DB::table('transactions')->where('id', 1)->value('lname'));
    }

    public function test_a_second_run_at_the_same_time_is_refused(): void
    {
        $this->on();
        $lock = Cache::lock('legacy-sync', 60);
        $lock->get();

        $this->actingAs($this->admin)->post('/admin/legacy-sync')->assertSessionHas('error');
        $this->assertDatabaseCount('transactions', 0);

        $lock->release();
    }

    public function test_an_unreachable_legacy_database_is_explained_not_a_crash(): void
    {
        $this->on();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/legacy.sqlite', 'prefix' => '']]);
        DB::purge('legacy');

        $this->actingAs($this->admin)->get('/admin/legacy-sync')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('connected', false)
            ->where('counts.0.legacy', null));
    }
}
