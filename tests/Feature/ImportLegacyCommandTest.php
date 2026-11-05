<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('legacy');

        $schema = Schema::connection('legacy');
        $schema->create('esd_casts_office', function (Blueprint $table) {
            $table->integer('office_id');
            $table->string('office_name');
            $table->string('office_code');
        });
        $schema->create('esd_casts_eligibility_tbl', function (Blueprint $table) {
            $table->integer('eligibility_id');
            $table->string('eligibility');
            $table->string('elig_code');
        });
        $schema->create('esd_casts_records', function (Blueprint $table) {
            $table->integer('id');
            $table->string('elig_code')->nullable();
        });

        $legacy = DB::connection('legacy');
        $legacy->table('esd_casts_office')->insert([
            ['office_id' => 1, 'office_name' => 'Examination Services Division', 'office_code' => 'ESD'],
            ['office_id' => 2, 'office_name' => 'CSC Field Office - Leyte I', 'office_code' => 'FO-LEYTE 1'],
        ]);
        $legacy->table('esd_casts_eligibility_tbl')->insert([
            ['eligibility_id' => 1, 'eligibility' => 'Honor Graduate', 'elig_code' => 'HGE'],
            ['eligibility_id' => 4, 'eligibility' => 'Skills Eligibility', 'elig_code' => 'MC 11'],
        ]);
        $legacy->table('esd_casts_records')->insert([
            ['id' => 1, 'elig_code' => 'HGE'],
            ['id' => 2, 'elig_code' => 'CSE-PR'],
            ['id' => 3, 'elig_code' => 'ODD-ONE'],
            ['id' => 4, 'elig_code' => ' '],
            ['id' => 5, 'elig_code' => null],
        ]);
    }

    public function test_it_copies_offices_and_eligibilities_keeping_the_legacy_ids(): void
    {
        $this->artisan('etts:import-legacy')->assertSuccessful();

        $this->assertDatabaseHas('offices', ['id' => 2, 'code' => 'FO-LEYTE 1', 'name' => 'CSC Field Office - Leyte I']);
        $this->assertDatabaseHas('eligibilities', ['id' => 4, 'code' => 'MC 11', 'name' => 'Skills Eligibility']);
    }

    public function test_each_office_gets_the_code_imis_knows_it_by(): void
    {
        $this->artisan('etts:import-legacy')->assertSuccessful();

        $this->assertDatabaseHas('offices', ['id' => 1, 'imis_code' => 'ESD']);
        $this->assertDatabaseHas('offices', ['id' => 2, 'code' => 'FO-LEYTE 1', 'imis_code' => 'LFO-I']);
    }

    public function test_an_office_without_a_known_imis_code_is_reported(): void
    {
        DB::connection('legacy')->table('esd_casts_office')->insert(['office_id' => 11, 'office_name' => 'New Office', 'office_code' => 'FO-NEW']);

        $this->artisan('etts:import-legacy')->expectsOutputToContain('FO-NEW')->assertSuccessful();

        $this->assertDatabaseHas('offices', ['id' => 11, 'imis_code' => null]);
    }

    public function test_the_imis_codes_are_unique(): void
    {
        $codes = array_values(config('etts.imis_office_codes'));

        $this->assertSame($codes, array_values(array_unique($codes)));
    }

    public function test_it_adds_the_confirmed_missing_eligibility_and_only_reports_the_rest(): void
    {
        $this->artisan('etts:import-legacy')
            ->expectsOutputToContain('ODD-ONE')
            ->assertSuccessful();

        $this->assertDatabaseHas('eligibilities', ['code' => 'CSE-PR', 'name' => 'CSE-Preference Rating']);
        $this->assertDatabaseMissing('eligibilities', ['code' => 'ODD-ONE']);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->artisan('etts:import-legacy')->assertSuccessful();
        $this->artisan('etts:import-legacy')->assertSuccessful();

        $this->assertDatabaseCount('offices', 2);
        $this->assertDatabaseCount('eligibilities', 3);
    }

    public function test_it_refuses_to_run_without_a_legacy_database(): void
    {
        config(['database.connections.legacy.database' => null]);

        $this->artisan('etts:import-legacy')->assertFailed();
    }
}
