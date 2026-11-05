<?php

namespace Tests\Feature\FieldOffice;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TransmittalDirection;
use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Models\IssuanceRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Models\User;
use App\Services\Transactions\TransactionWorkflow;
use App\Services\Transmittals\TransmittalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FieldOfficePortalTest extends TestCase
{
    use RefreshDatabase;

    private User $samar;

    private User $biliran;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        Office::create(['id' => 3, 'name' => 'CSC Field Office - Biliran', 'code' => 'FO-BILIRAN']);
        Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);

        $this->samar = User::factory()->create(['username' => 'samar.staff', 'name' => 'Samar Staff', 'office_id' => 2]);
        $this->biliran = User::factory()->create(['office_id' => 3]);
        $this->admin = User::factory()->admin()->create();
    }

    private function record(TransactionStatus $status = TransactionStatus::Submitted, int $office = 2, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, 'office_id' => $office, ...$extra]);
    }

    /**
     * @return array<string, mixed>
     */
    private function spelsInput(array $extra = []): array
    {
        return [
            'transaction_type' => TransactionType::Spels->value, 'office_id' => 2,
            'lname' => 'Cruz', 'fname' => 'Ana', 'mname' => 'Reyes',
            'transaction_date' => '2026-05-04', 'elig_code' => 'HGE',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function props(string $url, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->samar)->get($url)->assertOk()->viewData('page')['props'];
    }

    // ─── Access ───────────────────────────────────────────────────────────

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['/fo/dashboard', '/fo/transactions', '/fo/transmittals', '/fo/deleted'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_the_home_page_sends_a_field_office_user_to_their_dashboard(): void
    {
        $this->actingAs($this->samar)->get('/dashboard')->assertRedirect('/fo/dashboard');
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
    }

    public function test_administrators_do_not_use_the_field_office_portal(): void
    {
        foreach (['/fo/dashboard', '/fo/transactions', '/fo/transactions/create', '/fo/transmittals', '/fo/deleted'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertForbidden();
        }
    }

    public function test_a_user_without_an_office_gets_a_clear_message_and_nothing_else(): void
    {
        $nowhere = User::factory()->create(['office_id' => null]);

        $this->actingAs($nowhere)->get('/fo/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Fo/Dashboard')->where('office', null));

        foreach (['/fo/transactions', '/fo/transactions/create', '/fo/transmittals', '/fo/deleted'] as $url) {
            $this->actingAs($nowhere)->get($url)->assertForbidden();
        }
    }

    public function test_field_office_staff_cannot_reach_any_esd_screen_or_action(): void
    {
        $mine = $this->record();
        $draft = app(TransmittalService::class)->createDraft(TransmittalDirection::EsdToFo, [$this->record(TransactionStatus::Approved)->id], '', $this->admin);

        $this->actingAs($this->samar);
        foreach (['/admin/transactions', '/admin/transactions/create', "/admin/transactions/{$mine->id}/edit", '/admin/archive', '/admin/activity', '/admin/eligibilities', '/admin/transmittals', '/admin/transmittals/create', "/admin/transmittals/{$draft->id}", "/admin/transmittals/{$draft->id}/slip"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post("/admin/transactions/{$mine->id}/status", ['status' => 'Approved'])->assertForbidden();
        $this->post("/admin/transmittals/{$draft->id}/release")->assertForbidden();
    }

    // ─── Dashboard ────────────────────────────────────────────────────────

    public function test_the_dashboard_counts_only_the_signed_in_offices_records(): void
    {
        $this->record(TransactionStatus::Submitted);
        $this->record(TransactionStatus::Submitted);
        $this->record(TransactionStatus::Returned);
        $this->record(TransactionStatus::Forwarded);
        $this->record(TransactionStatus::Received);
        $this->record(TransactionStatus::Completed, 2, ['transaction_type' => TransactionType::Certification]);
        $this->record(TransactionStatus::Submitted, 3);
        $this->record(TransactionStatus::Returned, 3);

        $props = $this->props('/fo/dashboard');

        $this->assertSame('FO-SAMAR', $props['office']['code']);
        $this->assertSame(['pending_forward' => 2, 'returned' => 1, 'processing_ro' => 2, 'ready_release' => 1], $props['stats']);
    }

    public function test_the_dashboard_chart_covers_six_months_and_splits_the_types(): void
    {
        $this->record(TransactionStatus::Submitted, 2, ['transaction_date' => now()]);
        $this->record(TransactionStatus::Received, 2, ['transaction_date' => now(), 'transaction_type' => TransactionType::Authentication]);
        $this->record(TransactionStatus::Submitted, 2, ['transaction_date' => now()->subMonths(2)]);
        $this->record(TransactionStatus::Submitted, 2, ['transaction_date' => now()->subMonths(9)]);
        $this->record(TransactionStatus::Submitted, 3, ['transaction_date' => now()]);

        $chart = $this->props('/fo/dashboard')['chart'];

        $this->assertCount(6, $chart['labels']);
        $this->assertSame(1, $chart['spels'][5]);
        $this->assertSame(1, $chart['issuance'][5]);
        $this->assertSame(1, $chart['spels'][3]);
        $this->assertSame(3, array_sum($chart['spels']) + array_sum($chart['issuance']));
    }

    public function test_the_aging_queue_lists_old_submitted_applications_oldest_first(): void
    {
        $old = $this->record(TransactionStatus::Submitted, 2, ['transaction_date' => now()->subDays(30)]);
        $older = $this->record(TransactionStatus::Submitted, 2, ['transaction_date' => now()->subDays(45)]);
        $this->record(TransactionStatus::Submitted, 2, ['transaction_date' => now()->subDays(3)]);
        $this->record(TransactionStatus::Forwarded, 2, ['transaction_date' => now()->subDays(60)]);
        $this->record(TransactionStatus::Submitted, 3, ['transaction_date' => now()->subDays(90)]);

        $aging = $this->props('/fo/dashboard')['aging'];

        $this->assertSame([$older->id, $old->id], array_column($aging, 'id'));
        $this->assertSame(45, $aging[0]['days']);
    }

    // ─── Board ────────────────────────────────────────────────────────────

    public function test_the_board_shows_only_the_offices_own_records_and_ignores_an_office_filter(): void
    {
        $mine = $this->record();
        $this->record(TransactionStatus::Submitted, 3);

        $props = $this->props('/fo/transactions?office=3');

        $this->assertSame([$mine->id], array_column($props['transactions']['data'], 'id'));
        $this->assertSame(['active' => 1, 'history' => 0], $props['counts']);
    }

    public function test_the_board_marks_which_rows_the_office_may_still_work_on(): void
    {
        $submitted = $this->record(TransactionStatus::Submitted);
        $returned = $this->record(TransactionStatus::Returned);
        $forwarded = $this->record(TransactionStatus::Forwarded);
        $cert = $this->record(TransactionStatus::Received, 2, ['transaction_type' => TransactionType::Certification]);
        $completedCert = $this->record(TransactionStatus::Completed, 2, ['transaction_type' => TransactionType::Certification]);

        // A returned application sits on the History board, the rest on Active.
        $rows = collect([...$this->props('/fo/transactions')['transactions']['data'], ...$this->props('/fo/transactions?board=history')['transactions']['data']])->keyBy('id');

        $this->assertTrue($rows[$submitted->id]['can_work'] && $rows[$submitted->id]['can_forward']);
        $this->assertTrue($rows[$returned->id]['can_work'] && $rows[$returned->id]['can_forward']);
        $this->assertFalse($rows[$forwarded->id]['can_work']);
        $this->assertTrue($rows[$cert->id]['can_work']);
        $this->assertFalse($rows[$cert->id]['can_forward'], 'Only SPELS applications are forwarded.');
        $this->assertFalse($rows[$completedCert->id]['can_work']);
    }

    // ─── Filing and editing ───────────────────────────────────────────────

    public function test_filing_a_spels_application_always_files_it_at_the_users_own_office(): void
    {
        $this->actingAs($this->samar)->post('/fo/transactions', $this->spelsInput(['office_id' => 3]))
            ->assertRedirect('/fo/transactions')->assertSessionHasNoErrors();

        $record = Transaction::withoutGlobalScopes()->sole();
        $this->assertSame(2, $record->office_id);
        $this->assertSame(TransactionStatus::Submitted, $record->transaction_status);
    }

    public function test_filing_a_certification_receives_it_at_once(): void
    {
        $this->actingAs($this->samar)->post('/fo/transactions', [
            'transaction_type' => 'Certification', 'office_id' => 2, 'lname' => 'Lim', 'fname' => 'Ben',
            'transaction_date' => '2026-05-04', 'coe_copies' => 1, 'auth_copies' => 0,
        ])->assertSessionHasNoErrors();

        $record = Transaction::sole();
        $this->assertSame(TransactionStatus::Received, $record->transaction_status);
        $this->assertSame(100, $record->amount);
    }

    public function test_the_duplicate_rule_applies_to_the_office_too(): void
    {
        Transaction::factory()->approved()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 3]);

        $this->actingAs($this->samar)->post('/fo/transactions', $this->spelsInput())
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already on record'));

        $this->assertSame(0, Transaction::count());
    }

    public function test_the_form_opens_locked_to_the_office(): void
    {
        $this->actingAs($this->samar)->get('/fo/transactions/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transactions/Form')
                ->where('base', '/fo/transactions')
                ->where('officeLocked', true)
                ->where('record.office_id', 2)
                ->has('options.offices', 1));
    }

    public function test_a_record_still_with_the_office_can_be_edited_but_not_moved_to_another_office(): void
    {
        $record = $this->record(TransactionStatus::Submitted, 2, ['lname' => 'Cruz', 'fname' => 'Ana', 'mname' => 'Reyes', 'elig_code' => 'HGE']);

        $this->actingAs($this->samar)->get("/fo/transactions/{$record->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->where('mode', 'edit')->where('base', '/fo/transactions'));

        $this->actingAs($this->samar)->put("/fo/transactions/{$record->id}", $this->spelsInput(['office_id' => 3, 'mobile' => '09171234567']))->assertSessionHasNoErrors();

        $fresh = $record->fresh();
        $this->assertSame('09171234567', $fresh->mobile);
        $this->assertSame(2, $fresh->office_id);
    }

    public function test_a_record_the_regional_office_has_taken_up_is_read_only_for_the_office(): void
    {
        $forwarded = $this->record(TransactionStatus::Forwarded);
        $approved = $this->record(TransactionStatus::Approved);
        $completed = $this->record(TransactionStatus::Completed, 2, ['transaction_type' => TransactionType::Certification]);

        foreach ([$forwarded, $approved, $completed] as $record) {
            $this->actingAs($this->samar)->get("/fo/transactions/{$record->id}/edit")->assertForbidden();
            $this->actingAs($this->samar)->put("/fo/transactions/{$record->id}", $this->spelsInput())->assertForbidden();
        }
    }

    public function test_another_offices_record_does_not_exist_for_this_office(): void
    {
        $other = $this->record(TransactionStatus::Submitted, 3);

        $this->actingAs($this->samar)->get("/fo/transactions/{$other->id}/edit")->assertNotFound();
        $this->actingAs($this->samar)->put("/fo/transactions/{$other->id}", $this->spelsInput())->assertNotFound();
        $this->actingAs($this->samar)->post("/fo/transactions/{$other->id}/forward", ['date' => '2026-05-04'])->assertNotFound();
    }

    // ─── Forwarding ───────────────────────────────────────────────────────

    public function test_a_submitted_or_returned_application_can_be_forwarded(): void
    {
        $submitted = $this->record(TransactionStatus::Submitted);
        $returned = $this->record(TransactionStatus::Returned);

        foreach ([$submitted, $returned] as $record) {
            $this->actingAs($this->samar)->post("/fo/transactions/{$record->id}/forward", ['date' => '2026-05-04'])->assertSessionHas('status');

            $this->assertSame(TransactionStatus::Forwarded, $record->fresh()->transaction_status);
            $this->assertSame('2026-05-04', $record->fresh()->date_sent_to_ro->toDateString());
        }
    }

    public function test_forwarding_needs_a_date_and_a_record_still_with_the_office(): void
    {
        $submitted = $this->record(TransactionStatus::Submitted);
        $received = $this->record(TransactionStatus::Received);

        $this->actingAs($this->samar)->post("/fo/transactions/{$submitted->id}/forward", [])->assertSessionHasErrors('date');
        $this->actingAs($this->samar)->post("/fo/transactions/{$received->id}/forward", ['date' => '2026-05-04'])->assertForbidden();
        $this->assertSame(TransactionStatus::Submitted, $submitted->fresh()->transaction_status);
    }

    public function test_the_workflow_offers_the_office_only_forwarding(): void
    {
        $workflow = app(TransactionWorkflow::class);
        $labels = fn (Transaction $t, User $as) => array_column($workflow->availableActions($t->load('office'), $as), 'label');

        $this->assertSame(['Forward to RO'], $labels($this->record(TransactionStatus::Submitted), $this->samar));
        $this->assertSame(['Forward to RO'], $labels($this->record(TransactionStatus::Returned), $this->samar));
        $this->assertSame([], $labels($this->record(TransactionStatus::Received), $this->samar));
        $this->assertSame([], $labels($this->record(TransactionStatus::Returned), $this->admin));
        $this->assertSame(['Approve', 'Disapprove', 'Return to applicant'], $labels($this->record(TransactionStatus::Received), $this->admin));
    }

    // ─── Deleting ─────────────────────────────────────────────────────────

    public function test_the_office_can_delete_its_own_record_while_it_is_still_theirs(): void
    {
        $record = $this->record();

        $this->actingAs($this->samar)->post('/fo/transactions/delete', ['ids' => [$record->id], 'reason' => 'DUPLICATE', 'note' => 'Entered twice'])
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('transactions', ['id' => $record->id]);
        $this->assertDatabaseHas('deleted_transactions', ['id' => $record->id, 'deleted_by_name' => 'samar.staff', 'deletion_reason_code' => 'DUPLICATE']);
    }

    public function test_the_office_cannot_delete_what_the_regional_office_has_taken_up_or_another_offices_records(): void
    {
        $forwarded = $this->record(TransactionStatus::Forwarded);
        $other = $this->record(TransactionStatus::Submitted, 3);
        $mine = $this->record();

        foreach ([[$forwarded->id], [$other->id], [$mine->id, $forwarded->id]] as $ids) {
            $this->actingAs($this->samar)->post('/fo/transactions/delete', ['ids' => $ids, 'reason' => 'DUPLICATE', 'note' => 'Entered twice'])
                ->assertSessionHas('error');
        }

        $this->assertSame(3, Transaction::withoutGlobalScopes()->count());
    }

    public function test_the_deleted_page_lists_only_the_offices_own_deleted_records(): void
    {
        foreach ([[900, 2, 'Cruz'], [901, 3, 'Lim']] as [$id, $office, $lname]) {
            DB::table('deleted_transactions')->insert([
                'id' => $id, 'office_id' => $office, 'fname' => 'Ana', 'lname' => $lname, 'transaction_type' => 'Special Eligibility Application',
                'transaction_date' => '2026-01-05 08:00:00', 'transaction_status' => 'Submitted', 'deleted_at' => '2026-02-01 10:00:00',
                'deleted_by_name' => 'someone', 'deletion_reason_code' => 'DUPLICATE', 'deletion_reason_note' => 'Because',
            ]);
        }

        $this->actingAs($this->samar)->get('/fo/deleted')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Archive/Index')
                ->where('base', '/fo/deleted')
                ->where('options.offices', [])
                ->has('records.data', 1)
                ->where('records.data.0.name', 'Cruz, Ana'));
    }

    // ─── History and attachments ──────────────────────────────────────────

    public function test_the_office_sees_the_history_of_its_own_records_live_or_deleted_and_nothing_else(): void
    {
        $mine = $this->record();
        $other = $this->record(TransactionStatus::Submitted, 3);
        DB::table('legacy_activity_logs')->insert(['id' => 1, 'record_id' => $mine->id, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'details' => 'Added.', 'created_at' => '2020-01-01 08:00:00']);

        $this->actingAs($this->samar)->getJson("/fo/transactions/{$mine->id}/timeline")->assertOk()->assertJsonCount(1, 'entries');
        $this->actingAs($this->samar)->getJson("/fo/transactions/{$other->id}/timeline")->assertNotFound();

        $this->actingAs($this->samar)->post('/fo/transactions/delete', ['ids' => [$mine->id], 'reason' => 'OTHER', 'note' => 'No longer needed']);
        $this->actingAs($this->samar)->getJson("/fo/transactions/{$mine->id}/timeline")->assertOk()->assertJsonPath('deleted.by', 'samar.staff');

        DB::table('deleted_transactions')->insert([
            'id' => 905, 'office_id' => 3, 'fname' => 'X', 'lname' => 'Y', 'transaction_type' => 'Certification', 'transaction_date' => '2026-01-05 08:00:00',
            'transaction_status' => 'received', 'deleted_at' => '2026-02-01 10:00:00', 'deletion_reason_code' => 'OTHER',
        ]);
        $this->actingAs($this->samar)->getJson('/fo/transactions/905/timeline')->assertNotFound();
    }

    public function test_the_office_can_open_its_own_attachments_only(): void
    {
        Storage::disk('attachments')->put('mine.jpg', 'x');
        Storage::disk('attachments')->put('theirs.jpg', 'x');
        $mine = $this->record(TransactionStatus::Received, 2, ['transaction_type' => TransactionType::Certification]);
        $theirs = $this->record(TransactionStatus::Received, 3, ['transaction_type' => TransactionType::Certification]);
        IssuanceRequest::create(['transaction_id' => $mine->id, 'attachment_1' => 'mine.jpg']);
        IssuanceRequest::create(['transaction_id' => $theirs->id, 'attachment_1' => 'theirs.jpg']);

        $this->actingAs($this->samar)->get("/fo/transactions/{$mine->id}/attachments/1")->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'attachment.viewed')->where('subject_id', $mine->id)->count());

        $this->actingAs($this->samar)->get("/fo/transactions/{$theirs->id}/attachments/1")->assertNotFound();
        $this->actingAs($this->samar)->get("/fo/transactions/{$mine->id}/attachments/2")->assertNotFound();
    }

    // ─── Transmittals ─────────────────────────────────────────────────────

    public function test_building_and_sending_a_batch_up_to_the_regional_office(): void
    {
        $a = $this->record(TransactionStatus::Submitted);
        $b = $this->record(TransactionStatus::Returned);

        $this->actingAs($this->samar)->post('/fo/transmittals', [
            'ids' => [$a->id, $b->id],
            'manual' => [['manual_name' => 'Cover letter', 'manual_description' => 'Signed by the Director', 'manual_doc_type' => 'Letter']],
            'remarks' => 'October batch',
        ])->assertSessionHasNoErrors();

        $batch = Transmittal::sole();
        $this->assertSame(TransmittalDirection::FoToRo, $batch->direction);
        $this->assertSame(2, $batch->origin_office_id);
        $this->assertSame(1, $batch->target_office_id);
        $this->assertSame(3, $batch->items()->count());

        $this->actingAs($this->samar)->get("/fo/transmittals/{$batch->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transmittals/Show')
                ->where('portal', 'fo')
                ->where('base', '/fo/transmittals')
                ->where('capabilities.edit', true)
                ->where('capabilities.transmit', true)
                ->where('capabilities.release', false));

        $this->actingAs($this->samar)->post("/fo/transmittals/{$batch->id}/transmit", ['date' => '2026-06-01'])->assertSessionHas('status');

        $this->assertSame(TransactionStatus::Forwarded, $a->fresh()->transaction_status);
        $this->assertSame(TransactionStatus::Forwarded, $b->fresh()->transaction_status);
        $this->assertSame('Transmitted', $batch->fresh()->status->value);

        $this->actingAs($this->samar)->post("/fo/transmittals/{$batch->id}/transmit", [])->assertSessionHas('error');
    }

    public function test_a_batch_can_be_edited_and_deleted_while_it_is_a_draft(): void
    {
        $a = $this->record(TransactionStatus::Submitted);
        $extra = $this->record(TransactionStatus::Submitted);
        $this->actingAs($this->samar)->post('/fo/transmittals', ['ids' => [$a->id]]);
        $batch = Transmittal::sole();
        $base = "/fo/transmittals/{$batch->id}";

        $this->actingAs($this->samar)->getJson("{$base}/eligible")->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($this->samar)->post("{$base}/records", ['transaction_id' => $extra->id])->assertSessionHas('status');
        $this->actingAs($this->samar)->post("{$base}/manual", ['manual_name' => 'Letter', 'manual_description' => 'Cover letter'])->assertSessionHas('status');
        $this->assertSame(3, $batch->items()->count());

        $this->actingAs($this->samar)->delete($base)->assertRedirect('/fo/transmittals');
        $this->assertDatabaseMissing('transmittals', ['id' => $batch->id]);
    }

    public function test_a_batch_needs_something_in_it_and_only_the_offices_own_applications(): void
    {
        $other = $this->record(TransactionStatus::Submitted, 3);

        $this->actingAs($this->samar)->post('/fo/transmittals', [])->assertSessionHas('error');
        $this->actingAs($this->samar)->post('/fo/transmittals', ['ids' => [$other->id]])->assertSessionHas('error');
        $this->assertSame(0, Transmittal::withoutGlobalScopes()->count());
    }

    public function test_the_list_shows_the_offices_batches_and_the_released_ones_sent_down_but_never_esd_drafts(): void
    {
        $service = app(TransmittalService::class);
        $up = $service->createDraft(TransmittalDirection::FoToRo, [$this->record(TransactionStatus::Submitted)->id], '', $this->samar, 2);
        $releasedDown = $service->createDraft(TransmittalDirection::EsdToFo, [$this->record(TransactionStatus::Approved)->id], '', $this->admin);
        $service->release($releasedDown, $this->admin);
        $draftDown = $service->createDraft(TransmittalDirection::EsdToFo, [$this->record(TransactionStatus::Approved)->id], '', $this->admin);
        $service->createDraft(TransmittalDirection::FoToRo, [$this->record(TransactionStatus::Submitted, 3)->id], '', $this->biliran, 3);

        $sent = $this->props('/fo/transmittals');
        $this->assertSame([$up->id], array_column($sent['transmittals']['data'], 'id'));
        $this->assertSame(['ESD_TO_FO' => 1, 'FO_TO_RO' => 1], $sent['counts']);

        $received = $this->props('/fo/transmittals?direction=ESD_TO_FO');
        $this->assertSame([$releasedDown->id], array_column($received['transmittals']['data'], 'id'));

        $this->actingAs($this->samar)->get("/fo/transmittals/{$releasedDown->id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.edit', false)->where('capabilities.transmit', false));
        $this->actingAs($this->samar)->get("/fo/transmittals/{$draftDown->id}")->assertForbidden();
    }

    public function test_the_office_cannot_touch_a_batch_sent_down_or_another_offices_batch(): void
    {
        $service = app(TransmittalService::class);
        $releasedDown = $service->createDraft(TransmittalDirection::EsdToFo, [$this->record(TransactionStatus::Approved)->id], '', $this->admin);
        $service->release($releasedDown, $this->admin);
        $theirs = $service->createDraft(TransmittalDirection::FoToRo, [$this->record(TransactionStatus::Submitted, 3)->id], '', $this->biliran, 3);

        $this->actingAs($this->samar)->delete("/fo/transmittals/{$releasedDown->id}")->assertForbidden();
        $this->actingAs($this->samar)->post("/fo/transmittals/{$releasedDown->id}/transmit")->assertForbidden();
        $this->actingAs($this->samar)->get("/fo/transmittals/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->samar)->delete("/fo/transmittals/{$theirs->id}")->assertNotFound();
        $this->assertDatabaseHas('transmittals', ['id' => $theirs->id]);
    }

    public function test_the_slip_prints_for_the_offices_own_batch_with_the_regional_office_defaults(): void
    {
        config(['etts.transmittal.fo_to_ro.designation' => 'Director II']);
        $batch = app(TransmittalService::class)->createDraft(TransmittalDirection::FoToRo, [$this->record(TransactionStatus::Submitted)->id], '', $this->samar, 2);

        $this->actingAs($this->samar)->get("/fo/transmittals/{$batch->id}/slip")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transmittals/Slip')
                ->where('base', '/fo/transmittals')
                ->where('defaults.designation', 'Director II')
                ->has('defaults.addressee'));
    }
}
