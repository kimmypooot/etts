<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\IssuanceRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionProcessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer']);
    }

    /** Issuance fields for steps 1–3, all filled. */
    private const COMPLETE = [
        'purpose_of_request' => 'Employment', 'company_address' => 'Tacloban City Hall',
        'eligibility' => 'CSE-PR', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Tacloban',
        'book' => '12', 'page' => '34', 'sequence' => '5', 'examinee_no' => 'E-1', 'cert_no' => 'C-1', 'rating' => '85.5', 'release_date' => '2020-06-01',
    ];

    private function issuance(array $issuance = self::COMPLETE, TransactionStatus $status = TransactionStatus::Received, array $record = []): Transaction
    {
        $transaction = Transaction::factory()->create([
            'transaction_type' => TransactionType::Certification, 'office_id' => 1, 'lname' => 'Cruz', 'fname' => 'Ana',
            'transaction_status' => $status, 'official_receipt' => 'OR-1', 'transaction_details' => 'COE-1', 'amount' => 100,
            ...$record,
        ]);
        IssuanceRequest::create(['transaction_id' => $transaction->id, ...$issuance]);

        return $transaction;
    }

    private function version(Transaction $transaction): string
    {
        return $transaction->fresh()->updated_at->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Transaction $transaction, string $query = ''): array
    {
        return $this->actingAs($this->admin)->get("/admin/transactions/{$transaction->id}/process{$query}")->assertOk()->viewData('page')['props'];
    }

    private function step(int $step, Transaction $transaction, array $input): TestResponse
    {
        return $this->actingAs($this->admin)->post("/admin/transactions/{$transaction->id}/process/{$step}", [...$input, 'version' => $input['version'] ?? $this->version($transaction)]);
    }

    // ─── Opening ──────────────────────────────────────────────────────────

    public function test_an_issuance_record_opens_the_processing_page_and_spels_does_not(): void
    {
        $record = $this->issuance();
        $spels = Transaction::factory()->create(['office_id' => 1]);

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/process")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Transactions/Process')->where('id', $record->id)->where('office', 'Examination Services Division'));

        $this->actingAs($this->admin)->get("/admin/transactions/{$spels->id}/process")->assertNotFound();

        $fo = User::factory()->create(['office_id' => 1]);
        $this->actingAs($fo)->get("/admin/transactions/{$record->id}/process")->assertForbidden();
    }

    public function test_edit_redirects_issuance_records_and_still_opens_spels(): void
    {
        $record = $this->issuance();
        $spels = Transaction::factory()->create(['office_id' => 1]);

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/edit")->assertRedirect("/admin/transactions/{$record->id}/process");
        $this->actingAs($this->admin)->get("/admin/transactions/{$spels->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Transactions/Form'));
    }

    public function test_the_page_opens_on_the_first_incomplete_step(): void
    {
        $incomplete = $this->issuance([...self::COMPLETE, 'book' => null]);
        $complete = $this->issuance();

        $this->assertSame(3, $this->props($incomplete)['step']);
        $this->assertSame(3, $this->props($incomplete, '?step=5')['step']);
        $this->assertSame(['book'], $this->props($incomplete)['steps'][2]['missing']);
        $this->assertSame(5, $this->props($complete)['step']);
        $this->assertSame(2, $this->props($complete, '?step=2')['step']);
        $this->assertSame(['Personal info', 'Eligibility', 'Verification', 'Payment & files', 'Print & finish'], array_column($this->props($complete)['steps'], 'label'));
    }

    // ─── Saving ───────────────────────────────────────────────────────────

    public function test_each_step_enforces_its_required_fields(): void
    {
        $record = $this->issuance();

        $this->step(2, $record, ['eligibility' => '', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'X'])->assertSessionHasErrors('eligibility');
        $this->step(4, $record, ['transaction_type' => 'Certification', 'coe_copies' => 0, 'auth_copies' => 0, 'official_receipt' => 'OR-1'])->assertSessionHasErrors('coe_copies');
        $this->step(4, $record, ['transaction_type' => TransactionType::Spels->value, 'coe_copies' => 1, 'auth_copies' => 0, 'official_receipt' => 'OR-1'])->assertSessionHasErrors('transaction_type');
        $this->step(1, $record, ['lname' => 'Cruz', 'fname' => 'Ana', 'purpose_of_request' => 'Others', 'purpose_others' => ''])->assertSessionHasErrors('purpose_others');
    }

    public function test_a_step_save_keeps_the_other_steps_values(): void
    {
        $record = $this->issuance([...self::COMPLETE, 'book' => null]);

        $this->step(3, $record, ['book' => '99', 'page' => '34', 'sequence' => '5', 'examinee_no' => 'E-1', 'cert_no' => 'C-1', 'rating' => '85.5', 'release_date' => '2020-06-01'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/admin/transactions/{$record->id}/process?step=4");

        $issuance = $record->fresh()->issuanceRequest;
        $this->assertSame('99', $issuance->book);
        $this->assertSame('Tacloban City Hall', $issuance->company_address);
        $this->assertSame('CSE-PR', $issuance->eligibility);
        $this->assertSame('OR-1', $record->fresh()->official_receipt);
        $this->assertSame('COE-1', $record->fresh()->transaction_details);
    }

    public function test_a_save_without_changes_succeeds_and_returns_a_fresh_version(): void
    {
        $record = $this->issuance();
        $input = ['lname' => 'Cruz', 'fname' => 'Ana', 'purpose_of_request' => 'Employment', 'company_address' => 'Tacloban City Hall'];

        $this->step(1, $record, [...$input, 'version' => $this->props($record)['version']])->assertSessionHas('status');
        $this->step(1, $record, [...$input, 'version' => $this->props($record)['version']])->assertSessionHas('status');
    }

    public function test_a_step_that_changes_only_issuance_fields_moves_the_version(): void
    {
        $record = $this->issuance();
        $before = $this->version($record);
        $this->travel(5)->minutes();

        $this->step(2, $record, ['eligibility' => 'CHANGED', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Tacloban', 'version' => $before])
            ->assertSessionHas('status');

        $this->assertNotSame($before, $this->version($record));

        // A second processor still holding the old version is refused, not silently overwriting.
        $this->step(2, $record, ['eligibility' => 'OTHER', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Tacloban', 'version' => $before])
            ->assertSessionHas('error');
        $this->assertSame('CHANGED', $record->fresh()->issuanceRequest->eligibility);
    }

    public function test_steps_one_to_three_keep_the_stored_amount_and_details(): void
    {
        $record = $this->issuance(record: ['transaction_details' => 'COE-2, Auth-1 rush', 'amount' => 999]);

        $this->step(1, $record, ['lname' => 'Cruz', 'fname' => 'Anna', 'purpose_of_request' => 'Employment'])->assertSessionHas('status');

        $fresh = $record->fresh();
        $this->assertSame('Anna', $fresh->fname);
        $this->assertSame('COE-2, Auth-1 rush', $fresh->transaction_details);
        $this->assertSame(999, (int) $fresh->amount);
    }

    public function test_a_locked_record_with_a_blank_field_still_reaches_print_and_finish(): void
    {
        $record = $this->issuance([...self::COMPLETE, 'book' => null], TransactionStatus::Completed);

        $this->assertSame(5, $this->props($record)['step']);
        $this->assertSame(2, $this->props($record, '?step=2')['step']);
        $this->assertSame(4, $this->props($record, '?step=4')['step']);
    }

    public function test_a_stale_version_is_refused(): void
    {
        $record = $this->issuance();

        $this->step(1, $record, ['lname' => 'Changed', 'fname' => 'Ana', 'purpose_of_request' => 'Employment', 'version' => '2000-01-01 00:00:00'])->assertSessionHas('error');

        $this->assertSame('Cruz', $record->fresh()->lname);
    }

    public function test_saving_the_or_in_step_four_receives_and_assigns_the_officer(): void
    {
        $record = $this->issuance(status: TransactionStatus::Submitted, record: ['official_receipt' => null]);

        $this->step(4, $record, ['transaction_type' => 'Certification', 'coe_copies' => 2, 'auth_copies' => 0, 'official_receipt' => 'OR-77', 'or_date' => '2026-11-13'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/admin/transactions/{$record->id}/process?step=5");

        $fresh = $record->fresh();
        $this->assertSame(TransactionStatus::Received, $fresh->transaction_status);
        $this->assertSame('esd.officer', $fresh->action_officer);
        $this->assertSame(200, (int) $fresh->amount);
    }

    public function test_step_four_stores_the_original_document(): void
    {
        $record = $this->issuance();

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/process/4", [
            'transaction_type' => 'Certification and Authentication', 'coe_copies' => 1, 'auth_copies' => 1, 'official_receipt' => 'OR-1',
            'attachment_2' => UploadedFile::fake()->image('original.jpg'), 'version' => $this->version($record),
        ])->assertSessionHasNoErrors();

        $name = $record->fresh()->issuanceRequest->attachment_2;
        $this->assertNotNull($name);
        Storage::disk('attachments')->assertExists($name);
    }

    public function test_locked_records_refuse_step_saves(): void
    {
        foreach ([TransactionStatus::Completed, TransactionStatus::Released, TransactionStatus::Cancelled] as $status) {
            $record = $this->issuance(status: $status);

            $this->step(1, $record, ['lname' => 'Changed', 'fname' => 'Ana', 'purpose_of_request' => 'Employment'])->assertSessionHas('error');

            $this->assertSame('Cruz', $record->fresh()->lname, $status->value);
        }
    }

    public function test_a_step_save_on_a_spels_record_is_not_found(): void
    {
        $spels = Transaction::factory()->create(['office_id' => 1]);

        $this->step(1, $spels, ['lname' => 'X', 'fname' => 'Y', 'purpose_of_request' => 'Employment'])->assertNotFound();
    }

    // ─── Finishing ────────────────────────────────────────────────────────

    public function test_the_finish_action_and_vcas_link_follow_the_record(): void
    {
        $received = $this->props($this->issuance());
        $this->assertSame('complete', $received['finish']);
        $this->assertSame(TransactionStatus::Completed->value, $received['finishStatus']);
        $this->assertFalse($received['locked']);
        $this->assertStringStartsWith('vcas://Certification/Cruz/Ana/', $received['vcasUrl']);

        $auth = $this->props($this->issuance(record: ['transaction_type' => TransactionType::Authentication]));
        $this->assertNull($auth['vcasUrl']);

        $completed = $this->props($this->issuance(status: TransactionStatus::Completed));
        $this->assertSame('release', $completed['finish']);
        $this->assertSame(TransactionStatus::Released->value, $completed['finishStatus']);
        $this->assertTrue($completed['locked']);

        $submitted = $this->props($this->issuance(status: TransactionStatus::Submitted));
        $this->assertNull($submitted['finish']);
    }

    public function test_sending_to_vcas_is_audited(): void
    {
        $record = $this->issuance();

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/process/vcas")->assertRedirect();

        $this->assertSame(1, AuditLog::where('action', 'transaction.vcas_sent')->where('subject_id', $record->id)->count());
    }
}
