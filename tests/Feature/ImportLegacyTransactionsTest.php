<?php

namespace Tests\Feature;

use App\Models\Office;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyTransactionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('legacy');

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);

        $schema = Schema::connection('legacy');

        foreach (['esd_casts_records', 'esd_casts_records_deleted'] as $name) {
            $schema->create($name, function (Blueprint $table) use ($name) {
                $table->integer('id');
                $table->integer('office_id');
                $table->string('fname');
                $table->string('lname');
                $table->string('mname')->nullable();
                $table->string('extn')->nullable();
                $table->string('sex')->nullable();
                $table->string('transaction_type');
                $table->string('transaction_date');
                $table->string('elig_code')->nullable();
                $table->string('transaction_status');
                $table->string('date_completed')->nullable();
                $table->integer('amount')->nullable();
                $table->string('timestamp')->nullable();
                if ($name === 'esd_casts_records_deleted') {
                    $table->string('deleted_at');
                    $table->string('deletion_reason_code');
                }
            });
        }

        $schema->create('esd_casts_issuance_requests', function (Blueprint $table) {
            $table->integer('issuance_id');
            $table->integer('sets_records_id');
            $table->string('civil_status')->nullable();
            $table->string('date_of_exam_issuance')->nullable();
            $table->string('attachment_1')->nullable();
        });
        $schema->create('esd_casts_issuance_requests_deleted', function (Blueprint $table) {
            $table->integer('issuance_id');
            $table->integer('sets_records_id');
            $table->string('attachment_1')->nullable();
        });
        $schema->create('esd_etts_activity_logs', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('user_id')->nullable();
            $table->integer('record_id')->nullable();
            $table->string('username')->nullable();
            $table->string('action_type');
            $table->text('details')->nullable();
            $table->string('created_at')->nullable();
        });

        $schema->create('esd_casts_transmittals', function (Blueprint $table) {
            $table->integer('id');
            $table->string('transmittal_no');
            $table->string('direction');
            $table->integer('target_office_id');
            $table->integer('origin_office_id')->nullable();
            $table->string('status');
            $table->integer('created_by')->nullable();
            $table->string('created_at')->nullable();
            $table->string('remarks')->nullable();
        });
        $schema->create('esd_casts_transmittal_items', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('transmittal_id');
            $table->integer('record_id')->nullable();
            $table->integer('is_manual')->default(0);
            $table->string('manual_name')->nullable();
            $table->string('manual_description')->nullable();
            $table->string('manual_doc_type')->nullable();
            $table->string('remarks')->nullable();
        });

        $legacy = DB::connection('legacy');
        $base = ['office_id' => 1, 'transaction_type' => 'Special Eligibility Application', 'transaction_status' => 'Approved'];

        foreach ([
            $base + ['id' => 1, 'fname' => ' Ana ', 'lname' => 'Cruz', 'mname' => '', 'extn' => '', 'sex' => '', 'elig_code' => 'HGE', 'transaction_date' => '2026-01-05 08:00:00', 'amount' => 200, 'timestamp' => '2026-01-06 09:00:00'],
            $base + ['id' => 2, 'fname' => 'Ben', 'lname' => 'Lim ', 'mname' => 'Tan', 'elig_code' => '  ', 'transaction_date' => '2026-02-05 08:00:00', 'amount' => 350, 'timestamp' => '2026-02-06 09:00:00'],
['office_id' => 99] + $base + ['id' => 4, 'fname' => 'Dan', 'lname' => 'Go', 'transaction_date' => '2026-04-05 08:00:00'],
            $base + ['id' => 3, 'fname' => 'Carl', 'lname' => 'Uy', 'transaction_date' => '0000-00-00 00:00:00', 'date_completed' => '2026-03-10', 'timestamp' => '2026-03-11 09:00:00'],
        ] as $row) {
            $legacy->table('esd_casts_records')->insert($row);
        }
        $legacy->table('esd_casts_records_deleted')->insert([
            $base + ['id' => 50, 'fname' => 'Eve', 'lname' => 'Sy', 'transaction_date' => '2025-12-01 08:00:00', 'deleted_at' => '2026-01-01 10:00:00', 'deletion_reason_code' => 'DUPLICATE'],
        ]);
        foreach ([
            ['issuance_id' => 7, 'sets_records_id' => 1, 'civil_status' => 'Single', 'date_of_exam_issuance' => '0000-00-00', 'attachment_1' => 'abc.jpg'],
            ['issuance_id' => 8, 'sets_records_id' => 4, 'civil_status' => 'Married'],
        ] as $row) {
            $legacy->table('esd_casts_issuance_requests')->insert($row);
        }
        $legacy->table('esd_casts_issuance_requests_deleted')->insert([['issuance_id' => 9, 'sets_records_id' => 50, 'attachment_1' => 'old.pdf']]);
        $legacy->table('esd_casts_transmittals')->insert([
            ['id' => 30, 'transmittal_no' => 'TRN-20260101-000000-AAAA', 'direction' => 'ESD_TO_FO', 'target_office_id' => 1, 'origin_office_id' => null, 'status' => 'Released', 'created_by' => 12, 'created_at' => '2026-01-01 08:00:00', 'remarks' => null],
        ]);
        $legacy->table('esd_casts_transmittal_items')->insert([
            ['id' => 300, 'transmittal_id' => 30, 'record_id' => 1, 'is_manual' => 0, 'manual_name' => null, 'manual_description' => null, 'manual_doc_type' => null, 'remarks' => null],
            ['id' => 301, 'transmittal_id' => 30, 'record_id' => null, 'is_manual' => 1, 'manual_name' => 'Letter', 'manual_description' => 'Cover letter', 'manual_doc_type' => 'Letter', 'remarks' => 'Original'],
        ]);
        $legacy->table('esd_etts_activity_logs')->insert([
            ['id' => 1, 'user_id' => 5, 'record_id' => 1, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'created_at' => '2026-01-05 08:00:00'],
            ['id' => 2, 'user_id' => 5, 'record_id' => 50, 'username' => 'ana', 'action_type' => 'DELETE_RECORD', 'created_at' => '2026-01-06 08:00:00'],
            ['id' => 3, 'user_id' => null, 'record_id' => 777, 'username' => 'kiosk', 'action_type' => 'ADD_RECORD', 'created_at' => '2026-01-07 08:00:00'],
        ]);
    }

    public function test_it_copies_every_table_keeping_the_legacy_ids(): void
    {
        $this->artisan('etts:import-legacy-transactions')->assertFailed();

        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('issuance_requests', 1);
        $this->assertDatabaseHas('deleted_transactions', ['id' => 50, 'deletion_reason_code' => 'DUPLICATE']);
        $this->assertDatabaseHas('deleted_issuance_requests', ['id' => 9, 'transaction_id' => 50, 'attachment_1' => 'old.pdf']);
        $this->assertDatabaseCount('legacy_activity_logs', 3);
        $this->assertDatabaseHas('issuance_requests', ['id' => 7, 'transaction_id' => 1, 'attachment_1' => 'abc.jpg']);
    }

    public function test_transmittals_and_their_items_are_copied_with_the_new_column_names(): void
    {
        $this->artisan('etts:import-legacy-transactions');

        $this->assertDatabaseHas('transmittals', ['id' => 30, 'direction' => 'ESD_TO_FO', 'status' => 'Released', 'created_by_imis_id' => 12, 'remarks' => '']);
        $this->assertDatabaseHas('transmittal_items', ['id' => 300, 'transmittal_id' => 30, 'transaction_id' => 1, 'is_manual' => 0]);
        $this->assertDatabaseHas('transmittal_items', ['id' => 301, 'transaction_id' => null, 'is_manual' => 1, 'manual_name' => 'Letter', 'remarks' => 'Original']);
    }

    public function test_the_legacy_timestamp_becomes_updated_at(): void
    {
        $this->artisan('etts:import-legacy-transactions');

        $this->assertSame('2026-01-06 09:00:00', DB::table('transactions')->where('id', 1)->value('updated_at'));
    }

    public function test_identity_columns_are_trimmed_and_empty_values_become_null(): void
    {
        $this->artisan('etts:import-legacy-transactions');

        $one = DB::table('transactions')->where('id', 1)->first();
        $this->assertSame('Ana', $one->fname);
        $this->assertNull($one->mname);
        $this->assertNull($one->extn);
        $this->assertNull($one->sex);

        $two = DB::table('transactions')->where('id', 2)->first();
        $this->assertSame('Lim', $two->lname);
        $this->assertNull($two->elig_code);
    }

    public function test_a_zero_transaction_date_falls_back_to_the_completion_date_and_is_reported(): void
    {
        $this->artisan('etts:import-legacy-transactions')->expectsOutputToContain('Record #3 had no valid transaction date');

        $this->assertSame('2026-03-10 00:00:00', DB::table('transactions')->where('id', 3)->value('transaction_date'));
    }

    public function test_other_zero_dates_become_null(): void
    {
        $this->artisan('etts:import-legacy-transactions');

        $this->assertNull(DB::table('issuance_requests')->where('id', 7)->value('date_of_exam_issuance'));
    }

    public function test_rows_that_cannot_be_linked_are_skipped_and_reported(): void
    {
        $this->artisan('etts:import-legacy-transactions')
            ->expectsOutputToContain('office 99 does not exist')
            ->expectsOutputToContain('transaction 4 was not imported')
            ->assertFailed();

        $this->assertDatabaseMissing('transactions', ['id' => 4]);
        $this->assertDatabaseMissing('issuance_requests', ['id' => 8]);
    }

    public function test_activity_rows_pointing_nowhere_are_kept_and_reported(): void
    {
        $this->artisan('etts:import-legacy-transactions')->expectsOutputToContain('1 activity log row(s) point at a record');

        $this->assertDatabaseHas('legacy_activity_logs', ['id' => 3, 'record_id' => 777]);
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        $this->artisan('etts:import-legacy-transactions');
        $this->artisan('etts:import-legacy-transactions');

        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('legacy_activity_logs', 3);
    }

    public function test_a_rerun_refreshes_a_changed_row(): void
    {
        $this->artisan('etts:import-legacy-transactions');
        DB::connection('legacy')->table('esd_casts_records')->where('id', 2)->update(['transaction_status' => 'Disapproved']);

        $this->artisan('etts:import-legacy-transactions');

        $this->assertSame('Disapproved', DB::table('transactions')->where('id', 2)->value('transaction_status'));
    }

    public function test_fresh_removes_rows_that_no_longer_exist_in_the_legacy_database(): void
    {
        $this->artisan('etts:import-legacy-transactions');
        DB::connection('legacy')->table('esd_casts_records')->where('id', 2)->delete();

        $this->artisan('etts:import-legacy-transactions', ['--fresh' => true, '--no-interaction' => true]);

        $this->assertDatabaseMissing('transactions', ['id' => 2]);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_a_status_spelt_in_the_wrong_case_is_stored_in_its_canonical_spelling(): void
    {
        DB::connection('legacy')->table('esd_casts_records')->insert([
            'id' => 9, 'office_id' => 1, 'fname' => 'Zed', 'lname' => 'Yu', 'transaction_type' => 'Authentication',
            'transaction_status' => 'completed', 'transaction_date' => '2026-05-01 08:00:00',
        ]);

        $this->artisan('etts:import-legacy-transactions');

        $this->assertSame('Completed', DB::table('transactions')->where('id', 9)->value('transaction_status'));
        $this->assertSame(\App\Enums\TransactionStatus::Completed, \App\Models\Transaction::find(9)->transaction_status);
    }

    public function test_it_refuses_to_run_before_the_offices_are_imported(): void
    {
        DB::table('offices')->delete();

        $this->artisan('etts:import-legacy-transactions')->expectsOutputToContain('Import the offices first')->assertFailed();
    }
}
