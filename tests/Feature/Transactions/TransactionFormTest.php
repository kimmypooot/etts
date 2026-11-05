<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Models\IssuanceRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function spelsInput(array $extra = []): array
    {
        return [
            'transaction_type' => TransactionType::Spels->value,
            'office_id' => 1,
            'lname' => 'Cruz', 'fname' => 'Ana', 'mname' => 'Reyes',
            'transaction_date' => '2026-05-04',
            'elig_code' => 'HGE',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function certInput(array $extra = []): array
    {
        return [
            'transaction_type' => 'Certification and Authentication',
            'office_id' => 1,
            'lname' => 'Lim', 'fname' => 'Ben',
            'transaction_date' => '2026-05-04',
            'coe_copies' => 1, 'auth_copies' => 2,
            'purpose_of_request' => 'Employment', 'civil_status' => 'Single',
            ...$extra,
        ];
    }

    // ─── Access ───────────────────────────────────────────────────────────

    public function test_only_administrators_reach_the_forms(): void
    {
        $fo = User::factory()->create(['office_id' => 2]);
        $record = Transaction::factory()->create(['office_id' => 2]);

        $this->actingAs($fo)->get('/admin/transactions/create')->assertForbidden();
        $this->actingAs($fo)->post('/admin/transactions', $this->spelsInput())->assertForbidden();
        $this->actingAs($fo)->get("/admin/transactions/{$record->id}/edit")->assertForbidden();
        $this->actingAs($fo)->put("/admin/transactions/{$record->id}", $this->spelsInput())->assertForbidden();
        $this->actingAs($fo)->patch("/admin/transactions/{$record->id}/quick", [])->assertForbidden();
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    // ─── Create ───────────────────────────────────────────────────────────

    public function test_the_create_form_opens_with_sensible_defaults(): void
    {
        $this->actingAs($this->admin)->get('/admin/transactions/create?type=Authentication')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transactions/Form')
                ->where('mode', 'create')
                ->where('record.transaction_type', 'Authentication')
                ->where('record.office_id', 1)
                ->where('options.prices.coe', 100));
    }

    public function test_a_new_spels_application_starts_submitted(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['amount' => 500]))
            ->assertRedirect('/admin/transactions')->assertSessionHas('status');

        $record = Transaction::sole();
        $this->assertSame(TransactionStatus::Submitted, $record->transaction_status);
        $this->assertSame(500, $record->amount);
        $this->assertNull($record->date_sent_to_ro);
        $this->assertNull($record->issuanceRequest);
        $this->assertSame(1, AuditLog::where('action', 'transaction.created')->count());
    }

    public function test_a_new_certification_is_received_at_once_and_priced_from_its_copies(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput())->assertSessionHasNoErrors();

        $record = Transaction::sole();
        $this->assertSame(TransactionStatus::Received, $record->transaction_status);
        $this->assertSame(200, $record->amount); // 1 x 100 + 2 x 50
        $this->assertSame('COE-1, AUTH-2', $record->transaction_details);
        $this->assertSame('2026-05-04', $record->date_received_ro->toDateString());
        $this->assertSame('Employment', $record->issuanceRequest->purpose_of_request);
    }

    public function test_the_purpose_others_is_stored_with_its_text(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['purpose_of_request' => 'Others', 'purpose_others' => 'Visa application']));

        $this->assertSame('Others: Visa application', IssuanceRequest::sole()->purpose_of_request);
    }

    public function test_a_certification_needs_at_least_one_copy(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['coe_copies' => 0, 'auth_copies' => 0]))
            ->assertSessionHasErrors('coe_copies');
    }

    public function test_the_required_fields_are_enforced(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', ['transaction_type' => 'Special Eligibility Application'])
            ->assertSessionHasErrors(['office_id', 'lname', 'fname', 'transaction_date', 'elig_code']);

        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['elig_code' => 'NOPE']))->assertSessionHasErrors('elig_code');
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['office_id' => 99]))->assertSessionHasErrors('office_id');
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['official_receipt' => '<b>1</b>']))->assertSessionHasErrors('official_receipt');
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_second_spels_application_is_refused_when_one_is_already_approved(): void
    {
        Transaction::factory()->approved('2025-04-15')->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2]);

        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['lname' => '  Cruz  ']))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'already on record') && str_contains($message, '15 April 2025'));

        $this->assertSame(1, Transaction::count());
    }

    // ─── Edit ─────────────────────────────────────────────────────────────

    public function test_the_edit_form_opens_with_the_record_its_copies_and_a_version(): void
    {
        $record = Transaction::factory()->create(['transaction_type' => TransactionType::Authentication, 'office_id' => 1, 'transaction_details' => 'COE-1, AUTH-2', 'amount' => 200, 'transaction_status' => TransactionStatus::Received]);
        IssuanceRequest::create(['transaction_id' => $record->id, 'purpose_of_request' => 'Others: Visa', 'attachment_1' => 'abc.jpg']);

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transactions/Form')
                ->where('mode', 'edit')
                ->where('record.coe_copies', 1)
                ->where('record.auth_copies', 2)
                ->where('record.purpose_of_request', 'Others')
                ->where('record.purpose_others', 'Visa')
                ->where('attachments.0.url', route('attachments.show', [$record->id, 1]))
                ->has('version'));
    }

    public function test_editing_saves_the_changes_and_audits_the_field_names_only(): void
    {
        $record = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 1]);

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->spelsInput(['mobile' => '09171234567', 'official_receipt' => '555-1', 'version' => $record->fresh()->updated_at->format('Y-m-d H:i:s')]))
            ->assertRedirect('/admin/transactions')->assertSessionHasNoErrors();

        $this->assertSame('09171234567', $record->fresh()->mobile);

        $audit = AuditLog::where('action', 'transaction.updated')->sole();
        $this->assertContains('mobile', $audit->after['changed']);
        $this->assertStringNotContainsString('09171234567', json_encode($audit->after));
    }

    public function test_an_edit_made_on_a_stale_version_is_refused(): void
    {
        $record = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 1]);

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->spelsInput(['mobile' => '09170000000', 'version' => '2001-01-01 00:00:00']))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'modified elsewhere'));

        $this->assertNull($record->fresh()->mobile);
    }

    public function test_a_spels_application_cannot_become_a_certification(): void
    {
        $record = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 1]);

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->certInput())
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'cannot be changed into'));

        $this->assertSame(TransactionType::Spels, $record->fresh()->transaction_type);
    }

    public function test_a_certification_can_become_an_authentication_and_keeps_its_amount_when_no_copies_are_given(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput());
        $record = Transaction::sole();

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->certInput(['transaction_type' => 'Authentication', 'coe_copies' => 0, 'auth_copies' => 0]))
            ->assertSessionHasNoErrors();

        $fresh = $record->fresh();
        $this->assertSame(TransactionType::Authentication, $fresh->transaction_type);
        $this->assertSame(200, $fresh->amount);
        $this->assertSame('COE-1, AUTH-2', $fresh->transaction_details);
    }

    public function test_a_certification_without_an_issuance_row_gets_one_when_saved(): void
    {
        $record = Transaction::factory()->create(['transaction_type' => TransactionType::Certification, 'transaction_status' => TransactionStatus::Received, 'office_id' => 1]);

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->certInput(['transaction_type' => 'Certification', 'civil_status' => 'Married']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Married', $record->issuanceRequest()->first()->civil_status);
    }

    public function test_editing_a_pending_application_into_a_duplicate_of_an_approved_one_is_refused(): void
    {
        Transaction::factory()->approved()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2]);
        $other = Transaction::factory()->forApplicant('Lim', 'Ben', null, 'HGE')->create(['office_id' => 1]);

        $this->actingAs($this->admin)->put("/admin/transactions/{$other->id}", $this->spelsInput())
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'already on record'));

        $this->assertSame('Lim', $other->fresh()->lname);
    }

    public function test_recording_the_payment_of_an_esd_walk_in_confirms_it_as_received(): void
    {
        $record = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 1, 'transaction_status' => TransactionStatus::Submitted, 'transaction_date' => '2026-05-04 09:30:00']);

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->spelsInput(['official_receipt' => '555-9']))->assertSessionHasNoErrors();

        $fresh = $record->fresh();
        $this->assertSame(TransactionStatus::Received, $fresh->transaction_status);
        $this->assertSame('2026-05-04', $fresh->date_received_ro->toDateString());
        $this->assertSame('2026-05-04', $fresh->date_sent_to_ro->toDateString());
    }

    public function test_a_walk_in_without_an_or_number_or_a_field_office_filing_stays_submitted(): void
    {
        $esd = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 1, 'transaction_status' => TransactionStatus::Submitted]);
        $fo = Transaction::factory()->forApplicant('Lim', 'Ben', null, 'HGE')->create(['office_id' => 2, 'transaction_status' => TransactionStatus::Submitted]);

        $this->actingAs($this->admin)->put("/admin/transactions/{$esd->id}", $this->spelsInput());
        $this->actingAs($this->admin)->put("/admin/transactions/{$fo->id}", $this->spelsInput(['lname' => 'Lim', 'fname' => 'Ben', 'mname' => '', 'office_id' => 2, 'official_receipt' => '555-9']));

        $this->assertSame(TransactionStatus::Submitted, $esd->fresh()->transaction_status);
        $this->assertSame(TransactionStatus::Submitted, $fo->fresh()->transaction_status);
    }

    // ─── Quick edit ───────────────────────────────────────────────────────

    public function test_quick_edit_changes_only_its_fields(): void
    {
        $record = Transaction::factory()->create(['lname' => 'Cruz', 'office_id' => 1]);

        $this->actingAs($this->admin)->patch("/admin/transactions/{$record->id}/quick", [
            'official_receipt' => '555-1', 'remarks' => 'Checked', 'date_sent_to_ro' => '2026-05-01', 'date_received_ro' => '2026-05-02', 'date_completed' => '2026-05-03',
        ])->assertSessionHasNoErrors();

        $fresh = $record->fresh();
        $this->assertSame('555-1', $fresh->official_receipt);
        $this->assertSame('2026-05-02', $fresh->date_received_ro->toDateString());
        $this->assertSame('Cruz', $fresh->lname);
    }

    public function test_quick_edit_dates_must_run_in_order(): void
    {
        $record = Transaction::factory()->create(['office_id' => 1]);

        $this->actingAs($this->admin)->patch("/admin/transactions/{$record->id}/quick", ['date_sent_to_ro' => '2026-05-05', 'date_received_ro' => '2026-05-02'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'transmitted date must be on or before'));

        $this->actingAs($this->admin)->patch("/admin/transactions/{$record->id}/quick", ['date_received_ro' => '2026-05-05', 'date_completed' => '2026-05-02'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'received date must be on or before'));

        $this->assertNull($record->fresh()->date_sent_to_ro);
    }

    // ─── Attachments ──────────────────────────────────────────────────────

    public function test_an_uploaded_attachment_is_stored_privately_under_a_random_name(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['attachment_1' => UploadedFile::fake()->image('my id card.jpg', 100, 100)]))
            ->assertSessionHasNoErrors();

        $name = IssuanceRequest::sole()->attachment_1;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $name);
        Storage::disk('attachments')->assertExists($name);
    }

    public function test_attachments_that_are_not_pdf_jpg_or_png_are_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['attachment_1' => UploadedFile::fake()->create('run.php', 5, 'text/x-php')]))
            ->assertSessionHasErrors('attachment_1');

        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['attachment_1' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]))
            ->assertSessionHasErrors('attachment_1');

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_file_pretending_to_be_an_image_is_refused(): void
    {
        // The framework's fake files report the type their extension claims, so use a real file on disk.
        $path = tempnam(sys_get_temp_dir(), 'etts');
        file_put_contents($path, '<?php echo "not an image";');
        $fake = new UploadedFile($path, 'id.jpg', 'image/jpeg', null, true);

        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['attachment_1' => $fake]));

        // Refused either by the form rules or by the content check; nothing is saved or stored.
        $this->assertSame(0, Transaction::count());
        $this->assertSame([], Storage::disk('attachments')->files());
    }

    public function test_viewing_an_attachment_is_authorised_served_privately_and_audited(): void
    {
        $record = Transaction::factory()->create(['transaction_type' => TransactionType::Certification, 'office_id' => 1]);
        Storage::disk('attachments')->put('abc.jpg', 'jpegbytes');
        IssuanceRequest::create(['transaction_id' => $record->id, 'attachment_1' => 'abc.jpg']);

        $response = $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/attachments/1")->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertSame(1, AuditLog::where('action', 'attachment.viewed')->where('subject_id', $record->id)->count());

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/attachments/2")->assertNotFound();
        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/attachments/3")->assertNotFound();
    }

    public function test_field_office_staff_and_guests_cannot_open_attachments(): void
    {
        $record = Transaction::factory()->create(['office_id' => 2]);
        Storage::disk('attachments')->put('abc.jpg', 'x');
        IssuanceRequest::create(['transaction_id' => $record->id, 'attachment_1' => 'abc.jpg']);

        $this->get("/admin/transactions/{$record->id}/attachments/1")->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['office_id' => 2]))->get("/admin/transactions/{$record->id}/attachments/1")->assertForbidden();
    }

    public function test_a_stored_name_that_looks_like_a_path_is_never_served(): void
    {
        $record = Transaction::factory()->create(['office_id' => 1]);
        IssuanceRequest::create(['transaction_id' => $record->id, 'attachment_1' => '../../.env']);

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/attachments/1")->assertNotFound();
    }

    public function test_the_attachment_of_a_deleted_record_can_still_be_opened(): void
    {
        Storage::disk('attachments')->put('old.pdf', '%PDF-');
        \DB::table('deleted_issuance_requests')->insert(['id' => 5, 'transaction_id' => 900, 'attachment_1' => 'old.pdf']);

        $this->actingAs($this->admin)->get('/admin/transactions/900/attachments/1')->assertOk();
    }

    // ─── Export ───────────────────────────────────────────────────────────

    public function test_the_export_follows_the_board_and_filters_and_is_audited(): void
    {
        Transaction::factory()->create(['lname' => 'Cruz', 'office_id' => 1, 'transaction_status' => TransactionStatus::Received]);
        Transaction::factory()->create(['lname' => 'Lim', 'office_id' => 1, 'transaction_status' => TransactionStatus::Received]);
        Transaction::factory()->create(['lname' => 'Uy', 'office_id' => 1, 'transaction_status' => TransactionStatus::Approved]);

        $csv = $this->actingAs($this->admin)->get('/admin/transactions/export?search=cruz')->assertOk()->streamedContent();

        $this->assertStringContainsString('Cruz', $csv);
        $this->assertStringNotContainsString('Lim', $csv);
        $this->assertStringNotContainsString('Uy', $csv);
        $this->assertSame(1, AuditLog::where('action', 'transactions.exported')->count());

        $history = $this->actingAs($this->admin)->get('/admin/transactions/export?board=history')->streamedContent();
        $this->assertStringContainsString('Uy', $history);
    }

    public function test_spreadsheet_formulas_in_names_are_defused(): void
    {
        Transaction::factory()->create(['lname' => '=HYPERLINK("http://evil")', 'office_id' => 1, 'transaction_status' => TransactionStatus::Received]);

        $csv = $this->actingAs($this->admin)->get('/admin/transactions/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_only_administrators_can_export(): void
    {
        $this->get('/admin/transactions/export')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['office_id' => 2]))->get('/admin/transactions/export')->assertForbidden();
    }

    // ─── Activity export and live refresh ─────────────────────────────────

    public function test_the_activity_log_exports_the_tab_and_filters_as_csv_and_is_audited(): void
    {
        \DB::table('legacy_activity_logs')->insert(['id' => 1, 'record_id' => 5, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'details' => '=SUM(1)', 'created_at' => '2020-01-01 08:00:00']);
        \DB::table('legacy_activity_logs')->insert(['id' => 2, 'record_id' => 6, 'username' => 'ben', 'action_type' => 'UPDATE_STATUS', 'details' => 'Changed a status.', 'created_at' => '2020-02-01 08:00:00']);

        $csv = $this->actingAs($this->admin)->get('/admin/activity/export?source=legacy&search=ana')->assertOk()->streamedContent();

        $this->assertStringContainsString('ana', $csv);
        $this->assertStringContainsString("'=SUM(1)", $csv);
        $this->assertStringNotContainsString('ben', $csv);
        $this->assertSame(1, AuditLog::where('action', 'activity.exported')->count());
    }

    public function test_only_administrators_can_export_the_activity_log(): void
    {
        $this->get('/admin/activity/export')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['office_id' => 2]))->get('/admin/activity/export')->assertForbidden();
    }

    public function test_the_pulse_signature_changes_when_a_record_is_added_or_removed_and_respects_the_office(): void
    {
        Transaction::factory()->create(['office_id' => 1, 'transaction_status' => TransactionStatus::Received]);
        $fo = User::factory()->create(['office_id' => 2]);

        $before = $this->actingAs($this->admin)->get('/admin/transactions/pulse')->assertOk()->json('signature');
        $foBefore = $this->actingAs($fo)->get('/fo/transactions/pulse')->assertOk()->json('signature');

        $record = Transaction::factory()->create(['office_id' => 1, 'transaction_status' => TransactionStatus::Received]);

        $this->assertNotSame($before, $this->actingAs($this->admin)->get('/admin/transactions/pulse')->json('signature'));
        $this->assertSame($foBefore, $this->actingAs($fo)->get('/fo/transactions/pulse')->json('signature'));

        $record->delete();
        $this->assertSame($before, $this->actingAs($this->admin)->get('/admin/transactions/pulse')->json('signature'));
    }

    public function test_the_pulse_is_closed_to_guests_and_to_the_wrong_portal(): void
    {
        $this->get('/admin/transactions/pulse')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['office_id' => 2]))->get('/admin/transactions/pulse')->assertForbidden();
        $this->actingAs($this->admin)->get('/fo/transactions/pulse')->assertForbidden();
    }

    // ─── Activity log ─────────────────────────────────────────────────────

    public function test_the_activity_log_shows_current_and_legacy_entries_with_filters(): void
    {
        \DB::table('legacy_activity_logs')->insert(['id' => 1, 'record_id' => 5, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'details' => 'Added a record.', 'created_at' => '2020-01-01 08:00:00']);
        \DB::table('legacy_activity_logs')->insert(['id' => 2, 'record_id' => 6, 'username' => 'ben', 'action_type' => 'UPDATE_STATUS', 'details' => 'Changed a status.', 'created_at' => '2020-02-01 08:00:00']);
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput());

        $this->actingAs($this->admin)->get('/admin/activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Activity/Index')->has('entries.data', 1)->where('entries.data.0.action', 'transaction.created')->where('entries.data.0.who', 'esd.officer'));

        $this->actingAs($this->admin)->get('/admin/activity?source=legacy')
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 2)->where('actions', ['ADD_RECORD', 'UPDATE_STATUS']));

        $this->actingAs($this->admin)->get('/admin/activity?source=legacy&search=ben')
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.record_id', 6));

        $this->actingAs($this->admin)->get('/admin/activity?source=legacy&action=ADD_RECORD&from=2020-01-01&to=2020-01-31')
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_the_activity_log_is_for_administrators(): void
    {
        $this->actingAs(User::factory()->create(['office_id' => 2]))->get('/admin/activity')->assertForbidden();
    }
}
