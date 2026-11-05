<?php

namespace Tests\Feature\Transmittals;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Models\User;
use App\Services\Transactions\TransactionArchiver;
use App\Services\Transactions\WorkflowException;
use App\Services\Transmittals\TransmittalService;
use App\Enums\DeletionReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransmittalServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransmittalService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        Office::create(['id' => 3, 'name' => 'CSC Field Office - Biliran', 'code' => 'FO-BILIRAN']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer', 'name' => 'ESD Officer', 'imis_user_id' => 12]);
        $this->service = app(TransmittalService::class);
    }

    private function record(TransactionStatus $status, int $office = 2, TransactionType $type = TransactionType::Spels, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, 'office_id' => $office, 'transaction_type' => $type, ...$extra]);
    }

    private function down(array $records, string $remarks = ''): Transmittal
    {
        return $this->service->createDraft(TransmittalDirection::EsdToFo, array_map(fn ($r) => $r->id, $records), $remarks, $this->admin);
    }

    // ─── Eligibility ──────────────────────────────────────────────────────

    public function test_each_direction_accepts_its_own_statuses(): void
    {
        $approved = $this->record(TransactionStatus::Approved);
        $completed = $this->record(TransactionStatus::Completed, 2, TransactionType::Certification);
        $submitted = $this->record(TransactionStatus::Submitted);
        $received = $this->record(TransactionStatus::Received);
        $released = $this->record(TransactionStatus::ApprovedReleased);
        $returned = $this->record(TransactionStatus::Returned);

        $down = $this->service->eligible(TransmittalDirection::EsdToFo)->pluck('id')->all();
        $up = $this->service->eligible(TransmittalDirection::FoToRo)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$approved->id, $completed->id, $returned->id], $down);
        $this->assertEqualsCanonicalizing([$submitted->id, $returned->id], $up);
        $this->assertNotContains($received->id, [...$down, ...$up]);
        $this->assertNotContains($released->id, [...$down, ...$up]);
    }

    public function test_only_spels_applications_go_up_to_the_regional_office(): void
    {
        $this->record(TransactionStatus::Submitted, 2, TransactionType::Certification);

        $this->assertSame(0, $this->service->eligible(TransmittalDirection::FoToRo)->count());
    }

    public function test_a_record_in_an_open_draft_is_hidden_from_both_directions(): void
    {
        $returned = $this->record(TransactionStatus::Returned);
        $this->down([$returned]);

        $this->assertSame(0, $this->service->eligible(TransmittalDirection::EsdToFo)->count());
        $this->assertSame(0, $this->service->eligible(TransmittalDirection::FoToRo)->count());
    }

    public function test_a_record_comes_back_when_its_draft_is_deleted(): void
    {
        $record = $this->record(TransactionStatus::Approved);
        $draft = $this->down([$record]);

        $this->service->delete($draft, $this->admin);

        $this->assertSame(1, $this->service->eligible(TransmittalDirection::EsdToFo)->count());
        $this->assertDatabaseMissing('transmittals', ['id' => $draft->id]);
        $this->assertDatabaseCount('transmittal_items', 0);
    }

    // ─── Creating ─────────────────────────────────────────────────────────

    public function test_an_esd_batch_takes_the_office_of_its_records(): void
    {
        $draft = $this->down([$this->record(TransactionStatus::Approved), $this->record(TransactionStatus::Approved)], 'For release');

        $this->assertSame(TransmittalDirection::EsdToFo, $draft->direction);
        $this->assertSame(TransmittalStatus::Draft, $draft->status);
        $this->assertSame(2, $draft->target_office_id);
        $this->assertNull($draft->origin_office_id);
        $this->assertMatchesRegularExpression('/^TRN-\d{8}-\d{6}-[A-F0-9]{4}$/', $draft->transmittal_no);
        $this->assertSame(12, $draft->created_by_imis_id);
        $this->assertSame('For release', $draft->remarks);
        $this->assertSame(2, $draft->items()->count());
        $this->assertSame(1, AuditLog::where('action', 'transmittal.created')->count());
    }

    public function test_an_esd_batch_cannot_mix_field_offices(): void
    {
        $this->expectExceptionMessage('different field offices');
        $this->down([$this->record(TransactionStatus::Approved, 2), $this->record(TransactionStatus::Approved, 3)]);
    }

    public function test_a_batch_refuses_a_record_that_is_no_longer_eligible(): void
    {
        $record = $this->record(TransactionStatus::Received);

        $this->expectExceptionMessage('no longer available');
        $this->down([$record]);
    }

    public function test_an_empty_or_oversized_batch_is_refused(): void
    {
        try {
            $this->service->createDraft(TransmittalDirection::EsdToFo, [], '', $this->admin);
            $this->fail('An empty batch should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('No transactions', $e->getMessage());
        }

        $this->expectExceptionMessage('Too many');
        $this->service->createDraft(TransmittalDirection::EsdToFo, range(1, TransmittalService::MAX_RECORDS + 1), '', $this->admin);
    }

    public function test_a_field_office_batch_goes_to_esd_and_may_carry_other_documents(): void
    {
        $submitted = $this->record(TransactionStatus::Submitted, 2);

        $draft = $this->service->createDraft(TransmittalDirection::FoToRo, [$submitted->id], 'Batch 1', $this->admin, 2, [
            ['name' => 'Cover letter', 'description' => 'Signed by the director', 'type' => 'Letter'],
        ]);

        $this->assertSame(1, $draft->target_office_id);
        $this->assertSame(2, $draft->origin_office_id);
        $this->assertStringStartsWith('FTR-', $draft->transmittal_no);
        $this->assertSame(2, $draft->items()->count());
        $this->assertSame(1, $draft->items()->where('is_manual', true)->count());
    }

    public function test_a_field_office_cannot_send_another_offices_application(): void
    {
        $other = $this->record(TransactionStatus::Submitted, 3);

        $this->expectExceptionMessage('different office');
        $this->service->createDraft(TransmittalDirection::FoToRo, [$other->id], '', $this->admin, 2);
    }

    public function test_a_field_office_batch_needs_an_office_and_something_in_it(): void
    {
        $submitted = $this->record(TransactionStatus::Submitted, 2);

        try {
            $this->service->createDraft(TransmittalDirection::FoToRo, [$submitted->id], '', $this->admin, null);
            $this->fail('An unmapped account should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('not mapped to a field office', $e->getMessage());
        }

        $this->expectExceptionMessage('Add at least one');
        $this->service->createDraft(TransmittalDirection::FoToRo, [], '', $this->admin, 2);
    }

    // ─── Editing a draft ──────────────────────────────────────────────────

    public function test_records_can_be_added_and_removed_while_the_batch_is_a_draft(): void
    {
        $draft = $this->down([$this->record(TransactionStatus::Approved)]);
        $extra = $this->record(TransactionStatus::Approved);

        $item = $this->service->addRecord($draft, $extra->id, $this->admin);
        $this->assertSame(2, $draft->items()->count());

        $this->service->removeItem($draft, $item->id, $this->admin);
        $this->assertSame(1, $draft->items()->count());
    }

    public function test_a_record_of_another_office_cannot_be_added(): void
    {
        $draft = $this->down([$this->record(TransactionStatus::Approved, 2)]);
        $other = $this->record(TransactionStatus::Approved, 3);

        $this->expectExceptionMessage('different field office');
        $this->service->addRecord($draft, $other->id, $this->admin);
    }

    public function test_a_record_already_in_another_draft_cannot_be_added(): void
    {
        $record = $this->record(TransactionStatus::Approved, 2);
        $this->down([$record]);
        $second = $this->down([$this->record(TransactionStatus::Approved, 2)]);

        $this->expectExceptionMessage('no longer available');
        $this->service->addRecord($second, $record->id, $this->admin);
    }

    public function test_manual_documents_need_a_name_and_a_description(): void
    {
        $draft = $this->down([$this->record(TransactionStatus::Approved)]);

        foreach ([['name' => '', 'description' => 'x'], ['name' => 'x', 'description' => '  ']] as $row) {
            try {
                $this->service->addManual($draft, $row, $this->admin);
                $this->fail('An incomplete document should be refused.');
            } catch (WorkflowException) {
                $this->assertSame(1, $draft->items()->count());
            }
        }

        $item = $this->service->addManual($draft, ['name' => 'Letter', 'description' => 'Cover letter', 'type' => 'Letter', 'remarks' => str_repeat('x', 300)], $this->admin);
        $this->assertSame(200, mb_strlen($item->remarks));

        $this->service->updateManual($draft, $item->id, ['name' => 'Letter v2', 'description' => 'Revised'], $this->admin);
        $this->assertSame('Letter v2', $item->fresh()->manual_name);
    }

    public function test_only_a_manual_row_can_be_edited_as_a_document(): void
    {
        $draft = $this->down([$this->record(TransactionStatus::Approved)]);

        $this->expectExceptionMessage('not part of this transmittal');
        $this->service->updateManual($draft, $draft->items()->first()->id, ['name' => 'x', 'description' => 'y'], $this->admin);
    }

    public function test_the_note_beside_a_transaction_can_be_edited_in_a_draft(): void
    {
        $record = $this->record(TransactionStatus::Approved);
        $draft = $this->down([$record]);

        $this->service->updateRecordNote($draft, $record->id, 'transaction_details', 'COE-1', $this->admin);
        $this->service->updateRecordNote($draft, $record->id, 'remarks', '  ', $this->admin);

        $this->assertSame('COE-1', $record->fresh()->transaction_details);
        $this->assertNull($record->fresh()->remarks);

        $this->expectExceptionMessage('cannot be edited here');
        $this->service->updateRecordNote($draft, $record->id, 'lname', 'Hacked', $this->admin);
    }

    public function test_only_a_record_in_the_batch_can_have_its_note_edited(): void
    {
        $draft = $this->down([$this->record(TransactionStatus::Approved)]);
        $stranger = $this->record(TransactionStatus::Approved);

        $this->expectExceptionMessage('not part of this transmittal');
        $this->service->updateRecordNote($draft, $stranger->id, 'remarks', 'x', $this->admin);
    }

    // ─── Releasing (ESD to field office) ──────────────────────────────────

    public function test_releasing_marks_each_document_and_advances_the_status(): void
    {
        $approved = $this->record(TransactionStatus::Approved);
        $completed = $this->record(TransactionStatus::Completed, 2, TransactionType::Certification, ['date_completed' => '2026-05-01']);
        $draft = $this->down([$approved, $completed]);

        $this->service->release($draft, $this->admin);

        $this->assertSame(TransmittalStatus::Released, $draft->fresh()->status);
        $this->assertSame(TransactionStatus::ApprovedReleased, $approved->fresh()->transaction_status);
        $this->assertSame(TransactionStatus::Released, $completed->fresh()->transaction_status);
        $this->assertSame(today()->toDateString(), $approved->fresh()->doc_release_date->toDateString());
        $this->assertSame('Released', $approved->fresh()->document_status);
        $this->assertSame('esd.officer', $approved->fresh()->action_officer);
        $this->assertSame('2026-05-01', $completed->fresh()->date_completed->toDateString(), 'The completion date is not overwritten by the release.');
        $this->assertSame(1, AuditLog::where('action', 'transmittal.released')->count());
        $this->assertSame(2, AuditLog::where('action', 'transaction.released')->count());
    }

    public function test_a_disapproved_or_returned_application_is_handed_back_not_turned_into_an_approval(): void
    {
        $disapproved = $this->record(TransactionStatus::Disapproved);
        $returned = $this->record(TransactionStatus::Returned);
        $draft = $this->down([$disapproved, $returned]);

        $this->service->release($draft, $this->admin);

        $this->assertSame(TransactionStatus::Disapproved, $disapproved->fresh()->transaction_status);
        $this->assertSame(TransactionStatus::Returned, $returned->fresh()->transaction_status);
        $this->assertSame('Released', $disapproved->fresh()->document_status);
        $this->assertNotNull($returned->fresh()->doc_release_date);
    }

    public function test_a_released_batch_is_frozen(): void
    {
        $record = $this->record(TransactionStatus::Approved);
        $released = $this->down([$record]);
        $this->service->release($released, $this->admin);
        $extra = $this->record(TransactionStatus::Approved);

        foreach ([
            fn () => $this->service->addRecord($released, $extra->id, $this->admin),
            fn () => $this->service->addManual($released, ['name' => 'x', 'description' => 'y'], $this->admin),
            fn () => $this->service->removeItem($released, $released->items()->first()->id, $this->admin),
            fn () => $this->service->updateRemarks($released, 'x', $this->admin),
            fn () => $this->service->delete($released, $this->admin),
            fn () => $this->service->release($released, $this->admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A released batch must not change.');
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('already been released', $e->getMessage());
            }
        }

        $this->assertDatabaseHas('transmittals', ['id' => $released->id]);
    }

    public function test_releasing_stops_if_a_record_has_changed_since_it_was_added(): void
    {
        $a = $this->record(TransactionStatus::Approved);
        $b = $this->record(TransactionStatus::Approved);
        $draft = $this->down([$a, $b]);
        $b->forceFill(['transaction_status' => TransactionStatus::Received])->save();

        try {
            $this->service->release($draft, $this->admin);
            $this->fail('A changed record should stop the release.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('no longer eligible', $e->getMessage());
        }

        $this->assertSame(TransmittalStatus::Draft, $draft->fresh()->status);
        $this->assertSame(TransactionStatus::Approved, $a->fresh()->transaction_status, 'All or nothing.');
    }

    public function test_a_field_office_batch_cannot_be_released_and_an_esd_batch_cannot_be_transmitted(): void
    {
        $esd = $this->down([$this->record(TransactionStatus::Approved)]);
        $fo = $this->service->createDraft(TransmittalDirection::FoToRo, [$this->record(TransactionStatus::Submitted)->id], '', $this->admin, 2);

        try {
            $this->service->release($fo, $this->admin);
            $this->fail();
        } catch (WorkflowException) {
        }

        $this->expectException(WorkflowException::class);
        $this->service->transmit($esd, '2026-05-01', $this->admin);
    }

    // ─── Transmitting (field office to RO) ────────────────────────────────

    public function test_transmitting_forwards_the_applications(): void
    {
        $new = $this->record(TransactionStatus::Submitted, 2);
        $corrected = $this->record(TransactionStatus::Returned, 2);
        $draft = $this->service->createDraft(TransmittalDirection::FoToRo, [$new->id, $corrected->id], '', $this->admin, 2);

        $this->service->transmit($draft, '2026-06-01', $this->admin);

        $this->assertSame(TransmittalStatus::Transmitted, $draft->fresh()->status);
        foreach ([$new, $corrected] as $record) {
            $this->assertSame(TransactionStatus::Forwarded, $record->fresh()->transaction_status);
            $this->assertSame('2026-06-01', $record->fresh()->date_sent_to_ro->toDateString());
        }
        $this->assertSame(2, AuditLog::where('action', 'transaction.status_changed')->count());
    }

    public function test_a_batch_of_only_other_documents_can_be_transmitted(): void
    {
        $draft = $this->service->createDraft(TransmittalDirection::FoToRo, [], '', $this->admin, 2, [['name' => 'Letter', 'description' => 'Cover letter']]);

        $this->assertSame(TransmittalStatus::Transmitted, $this->service->transmit($draft, '2026-06-01', $this->admin)->status);
    }

    public function test_transmitting_stops_if_an_application_has_since_moved_on(): void
    {
        $moved = $this->record(TransactionStatus::Submitted, 2);
        $draft = $this->service->createDraft(TransmittalDirection::FoToRo, [$moved->id], '', $this->admin, 2);
        $moved->forceFill(['transaction_status' => TransactionStatus::Forwarded])->save();

        $this->expectExceptionMessage('no longer eligible');
        $this->service->transmit($draft, '2026-06-01', $this->admin);
    }

    // ─── Showing a batch ──────────────────────────────────────────────────

    public function test_a_deleted_transaction_stays_on_a_released_slip_from_the_archive(): void
    {
        $record = $this->record(TransactionStatus::Approved, 2, extra: ['lname' => 'Cruz', 'fname' => 'Ana']);
        $draft = $this->down([$record]);
        $this->service->release($draft, $this->admin);

        app(TransactionArchiver::class)->archive([$record->id], DeletionReason::Duplicate, 'Entered twice', $this->admin);

        $item = $this->service->describeItems($draft->fresh())[0];
        $this->assertSame('Cruz, Ana', $item['name']);
        $this->assertTrue($item['deleted']);
        $this->assertDatabaseCount('transmittal_items', 1);
    }

    public function test_a_record_in_an_open_draft_cannot_be_deleted(): void
    {
        $record = $this->record(TransactionStatus::Approved);
        $draft = $this->down([$record]);

        try {
            app(TransactionArchiver::class)->archive([$record->id], DeletionReason::Duplicate, 'Entered twice', $this->admin);
            $this->fail('A record in a draft must not be deleted.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString($draft->transmittal_no, $e->getMessage());
        }

        $this->assertDatabaseHas('transactions', ['id' => $record->id]);
    }

    public function test_the_items_are_described_for_the_screen_and_the_slip(): void
    {
        $record = $this->record(TransactionStatus::Approved, 2, extra: ['lname' => 'Cruz', 'fname' => 'Ana', 'elig_code' => 'HGE', 'transaction_details' => 'COE-1']);
        $draft = $this->down([$record]);
        $this->service->addManual($draft, ['name' => 'Letter', 'description' => 'Cover letter', 'type' => 'Letter'], $this->admin);

        $items = $this->service->describeItems($draft);

        $this->assertSame('Cruz, Ana', $items[0]['name']);
        $this->assertSame('HGE', $items[0]['eligibility']);
        $this->assertSame('COE-1', $items[0]['details']);
        $this->assertFalse($items[0]['deleted']);
        $this->assertTrue($items[1]['is_manual']);
        $this->assertSame('Cover letter', $items[1]['description']);
    }

    public function test_the_office_scope_keeps_a_field_office_user_to_their_own_batches(): void
    {
        $mine = $this->down([$this->record(TransactionStatus::Approved, 2)]);
        $other = $this->down([$this->record(TransactionStatus::Approved, 3)]);

        $this->actingAs(User::factory()->create(['office_id' => 2]));
        $this->assertSame([$mine->id], Transmittal::pluck('id')->all());

        $this->actingAs(User::factory()->create(['office_id' => null]));
        $this->assertSame(0, Transmittal::count());

        $this->actingAs($this->admin);
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], Transmittal::pluck('id')->all());
    }
}
