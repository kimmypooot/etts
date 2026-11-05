<?php

namespace Tests\Feature\Transactions;

use App\Enums\DeletionReason;
use App\Models\AuditLog;
use App\Models\IssuanceRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionArchiver;
use App\Services\Transactions\WorkflowException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionArchiverTest extends TestCase
{
    use RefreshDatabase;

    private TransactionArchiver $archiver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer', 'imis_user_id' => 77]);
        $this->archiver = app(TransactionArchiver::class);
    }

    public function test_a_deleted_transaction_moves_to_the_archive_with_who_when_and_why(): void
    {
        $record = Transaction::factory()->create(['lname' => 'Cruz', 'fname' => 'Ana', 'amount' => 200]);

        $count = $this->archiver->archive([$record->id], DeletionReason::Duplicate, 'Entered twice by mistake', $this->admin);

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('transactions', ['id' => $record->id]);
        $this->assertDatabaseHas('deleted_transactions', [
            'id' => $record->id,
            'lname' => 'Cruz',
            'amount' => 200,
            'deleted_by_id' => 77,
            'deleted_by_name' => 'esd.officer',
            'deletion_reason_code' => 'DUPLICATE',
            'deletion_reason_note' => 'Entered twice by mistake',
        ]);
    }

    public function test_the_issuance_request_and_its_attachment_names_are_archived_with_it(): void
    {
        $record = Transaction::factory()->create();
        IssuanceRequest::create(['id' => 12, 'transaction_id' => $record->id, 'civil_status' => 'Single', 'attachment_1' => 'abc.jpg']);

        $this->archiver->archive([$record->id], DeletionReason::EncodingError, 'Wrong applicant entered', $this->admin);

        $this->assertDatabaseMissing('issuance_requests', ['id' => 12]);
        $this->assertDatabaseHas('deleted_issuance_requests', ['id' => 12, 'transaction_id' => $record->id, 'attachment_1' => 'abc.jpg', 'deleted_by_id' => 77]);
    }

    public function test_one_audit_entry_is_written_per_record(): void
    {
        $a = Transaction::factory()->create();
        $b = Transaction::factory()->create();

        $this->archiver->archive([$a->id, $b->id], DeletionReason::TestData, 'Test entries', $this->admin);

        $this->assertSame(2, AuditLog::where('action', 'transaction.deleted')->count());
        $this->assertStringContainsString('part of a 2-record deletion', AuditLog::where('subject_id', $a->id)->first()->after['summary']);
    }

    public function test_the_note_must_be_a_sensible_length(): void
    {
        $record = Transaction::factory()->create();

        foreach (['abc', str_repeat('x', DeletionReason::NOTE_MAX + 1), '     '] as $note) {
            try {
                $this->archiver->archive([$record->id], DeletionReason::Other, $note, $this->admin);
                $this->fail('A bad note should have been refused.');
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('explain the deletion', $e->getMessage());
            }
        }

        $this->assertDatabaseHas('transactions', ['id' => $record->id]);
    }

    public function test_nothing_is_deleted_when_one_record_is_missing(): void
    {
        $record = Transaction::factory()->create();

        try {
            $this->archiver->archive([$record->id, 999999], DeletionReason::Duplicate, 'Entered twice', $this->admin);
            $this->fail('A missing record should have stopped the deletion.');
        } catch (WorkflowException) {
            $this->assertDatabaseHas('transactions', ['id' => $record->id]);
            $this->assertSame(0, DB::table('deleted_transactions')->count());
        }
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(WorkflowException::class);
        $this->archiver->archive([], DeletionReason::Duplicate, 'Entered twice', $this->admin);
    }
}
