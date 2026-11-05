<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
    }

    private function refer(string $table, int $id, ?string $one, ?string $two = null): void
    {
        if ($table === 'issuance_requests') {
            Transaction::factory()->create(['id' => $id]);
        }

        DB::table($table)->insert(['id' => $id, 'transaction_id' => $id, 'attachment_1' => $one, 'attachment_2' => $two]);
    }

    public function test_it_passes_when_every_referenced_file_is_present(): void
    {
        Storage::disk('attachments')->put('a.jpg', 'x');
        Storage::disk('attachments')->put('b.pdf', 'x');
        $this->refer('issuance_requests', 1, 'a.jpg', 'b.pdf');

        $this->artisan('etts:verify-attachments')->assertSuccessful();
    }

    public function test_a_missing_file_fails_and_is_listed(): void
    {
        Storage::disk('attachments')->put('a.jpg', 'x');
        $this->refer('issuance_requests', 1, 'a.jpg', 'gone.pdf');

        $this->artisan('etts:verify-attachments', ['--list' => true])
            ->expectsOutputToContain('Missing: gone.pdf')
            ->assertFailed();
    }

    public function test_archived_issuance_requests_count_as_references(): void
    {
        Storage::disk('attachments')->put('old.pdf', 'x');
        $this->refer('deleted_issuance_requests', 5, 'old.pdf');

        $this->artisan('etts:verify-attachments', ['--list' => true])->assertSuccessful();
    }

    public function test_unreferenced_files_are_reported_but_do_not_fail(): void
    {
        Storage::disk('attachments')->put('stray.jpg', 'x');

        $this->artisan('etts:verify-attachments', ['--list' => true])
            ->expectsOutputToContain('Unreferenced: stray.jpg')
            ->assertSuccessful();
    }

    public function test_quarantine_moves_unreferenced_files_and_never_deletes(): void
    {
        Storage::disk('attachments')->put('stray.jpg', 'x');
        Storage::disk('attachments')->put('keep.jpg', 'x');
        $this->refer('issuance_requests', 1, 'keep.jpg');

        $this->artisan('etts:verify-attachments', ['--quarantine' => true])->assertSuccessful();

        Storage::disk('attachments')->assertExists('keep.jpg');
        Storage::disk('attachments')->assertMissing('stray.jpg');
        Storage::disk('attachments')->assertExists('_unreferenced/stray.jpg');
    }

    public function test_a_referenced_file_of_a_disallowed_type_fails(): void
    {
        Storage::disk('attachments')->put('run.php', 'x');
        $this->refer('issuance_requests', 1, 'run.php');

        $this->artisan('etts:verify-attachments')->assertFailed();
    }

    public function test_a_reference_that_looks_like_a_path_fails(): void
    {
        $this->refer('issuance_requests', 1, '../../.env');

        $this->artisan('etts:verify-attachments')->assertFailed();
    }
}
