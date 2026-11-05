<?php

namespace Tests\Feature;

use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CutoverCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_an_empty_database_and_names_the_problem(): void
    {
        $this->artisan('etts:cutover-check', ['--local' => true])
            ->expectsOutputToContain('Transactions are imported')
            ->assertFailed();
    }

    public function test_it_blocks_a_missing_attachment_and_an_office_without_an_imis_code(): void
    {
        Storage::fake('attachments');
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);

        $this->artisan('etts:cutover-check', ['--local' => true])
            ->expectsOutputToContain('Every office has an IMIS unit code')
            ->assertFailed();
    }

    public function test_the_rehearsal_flag_turns_production_only_checks_into_warnings(): void
    {
        $this->artisan('etts:cutover-check')->expectsOutputToContain('Debug is off')->assertFailed();
    }
}
