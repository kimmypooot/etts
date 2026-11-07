<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionWorkflow;
use App\Services\Transactions\WorkflowException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private TransactionWorkflow $workflow;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer']);
        $this->workflow = app(TransactionWorkflow::class);
    }

    private function spels(TransactionStatus $status, int $office = 2, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, 'office_id' => $office, ...$extra]);
    }

    private function cert(TransactionStatus $status, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_type' => TransactionType::Certification, 'transaction_status' => $status, 'office_id' => 1, ...$extra]);
    }

    // ─── forward / receive ────────────────────────────────────────────────

    public function test_a_submitted_spels_record_can_be_forwarded(): void
    {
        $record = $this->spels(TransactionStatus::Submitted);

        $this->workflow->forward($record, '2026-05-01', $this->admin);

        $this->assertSame(TransactionStatus::Forwarded, $record->fresh()->transaction_status);
        $this->assertSame('2026-05-01', $record->fresh()->date_sent_to_ro->toDateString());
        $this->assertSame(1, AuditLog::where('action', 'transaction.status_changed')->where('subject_id', $record->id)->count());
    }

    public function test_only_a_submitted_spels_record_can_be_forwarded(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->forward($this->spels(TransactionStatus::Received), '2026-05-01', $this->admin);
    }

    public function test_a_certification_is_never_forwarded(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->forward($this->cert(TransactionStatus::Submitted), '2026-05-01', $this->admin);
    }

    public function test_a_forwarded_record_can_be_received(): void
    {
        $record = $this->spels(TransactionStatus::Forwarded);

        $this->workflow->receive($record, '2026-05-02', $this->admin);

        $this->assertSame(TransactionStatus::Received, $record->fresh()->transaction_status);
        $this->assertSame('2026-05-02', $record->fresh()->date_received_ro->toDateString());
    }

    public function test_a_filing_made_at_esd_has_no_forwarding_leg(): void
    {
        $record = $this->spels(TransactionStatus::Submitted, office: 1);

        $this->workflow->receive($record, '2026-05-02', $this->admin);

        $this->assertSame(TransactionStatus::Received, $record->fresh()->transaction_status);
    }

    public function test_a_field_office_filing_must_be_forwarded_before_it_is_received(): void
    {
        $record = $this->spels(TransactionStatus::Submitted, office: 2);

        $this->expectException(WorkflowException::class);
        $this->workflow->receive($record, '2026-05-02', $this->admin);
    }

    // ─── single status changes ────────────────────────────────────────────

    public function test_a_certification_runs_received_completed_released(): void
    {
        $record = $this->cert(TransactionStatus::Received);

        $this->workflow->changeStatus($record, TransactionStatus::Completed, $this->admin, '2026-05-03');
        $this->workflow->changeStatus($record->fresh(), TransactionStatus::Released, $this->admin, '2026-05-04');

        $fresh = $record->fresh();
        $this->assertSame(TransactionStatus::Released, $fresh->transaction_status);
        $this->assertSame('2026-05-04', $fresh->date_completed->toDateString());
        $this->assertSame('esd.officer', $fresh->action_officer);
    }

    public function test_the_flow_cannot_be_skipped(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('must be completed before it can be released');
        $this->workflow->changeStatus($this->cert(TransactionStatus::Received), TransactionStatus::Released, $this->admin);
    }

    public function test_a_finalised_transaction_cannot_be_cancelled(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->changeStatus($this->cert(TransactionStatus::Completed), TransactionStatus::Cancelled, $this->admin, remarks: 'Changed mind');
    }

    public function test_a_reason_is_required_to_cancel_disapprove_or_return(): void
    {
        foreach ([[$this->cert(TransactionStatus::Received), TransactionStatus::Cancelled], [$this->spels(TransactionStatus::Received), TransactionStatus::Disapproved], [$this->spels(TransactionStatus::Received), TransactionStatus::Returned]] as [$record, $to]) {
            try {
                $this->workflow->changeStatus($record, $to, $this->admin, remarks: '  ');
                $this->fail("{$to->value} without a reason should be refused");
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('reason or remark is required', $e->getMessage());
            }
        }
    }

    public function test_remarks_are_appended_with_the_date_and_status(): void
    {
        $record = $this->spels(TransactionStatus::Received, extra: ['remarks' => 'First note']);

        $this->workflow->changeStatus($record, TransactionStatus::Returned, $this->admin, '2026-05-05', 'Incomplete documents');

        $this->assertSame("First note\n[2026-05-05 Returned]: Incomplete documents", $record->fresh()->remarks);
    }

    public function test_a_certification_cannot_be_approved_and_a_spels_record_cannot_be_released(): void
    {
        foreach ([[$this->cert(TransactionStatus::Received), TransactionStatus::Approved], [$this->spels(TransactionStatus::Received), TransactionStatus::Released]] as [$record, $to]) {
            try {
                $this->workflow->changeStatus($record, $to, $this->admin);
                $this->fail("{$to->value} should not be allowed for {$record->transaction_type->value}");
            } catch (WorkflowException) {
                $this->assertSame(TransactionStatus::Received, $record->fresh()->transaction_status);
            }
        }
    }

    public function test_spels_approval_then_release(): void
    {
        $record = $this->spels(TransactionStatus::Received, extra: ['elig_code' => 'HGE']);

        $this->workflow->changeStatus($record, TransactionStatus::Approved, $this->admin);
        $this->workflow->changeStatus($record->fresh(), TransactionStatus::ApprovedReleased, $this->admin);

        $this->assertSame(TransactionStatus::ApprovedReleased, $record->fresh()->transaction_status);
    }

    public function test_approved_released_needs_a_prior_approval(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->changeStatus($this->spels(TransactionStatus::Received), TransactionStatus::ApprovedReleased, $this->admin);
    }

    public function test_a_second_approval_for_the_same_applicant_and_eligibility_is_refused(): void
    {
        Transaction::factory()->approved()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 1]);
        $second = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2, 'transaction_status' => TransactionStatus::Received]);

        try {
            $this->workflow->changeStatus($second, TransactionStatus::Approved, $this->admin);
            $this->fail('The duplicate approval should have been refused.');
        } catch (WorkflowException $e) {
            $this->assertSame('spels_duplicate', $e->reason);
            $this->assertStringContainsString('already on record', $e->getMessage());
        }

        $this->assertSame(TransactionStatus::Received, $second->fresh()->transaction_status);
    }

    // ─── batch ────────────────────────────────────────────────────────────

    public function test_a_batch_receives_several_forwarded_records(): void
    {
        $records = [$this->spels(TransactionStatus::Forwarded), $this->spels(TransactionStatus::Forwarded)];

        $count = $this->workflow->batch(array_map(fn ($r) => $r->id, $records), TransactionStatus::Received, $this->admin);

        $this->assertSame(2, $count);
        $this->assertSame(2, Transaction::where('transaction_status', TransactionStatus::Received)->count());
        $this->assertSame(2, AuditLog::where('action', 'transaction.status_changed')->count());
    }

    public function test_a_batch_must_have_one_current_status(): void
    {
        $a = $this->spels(TransactionStatus::Forwarded);
        $b = $this->spels(TransactionStatus::Received);

        $this->expectExceptionMessage('do not all have the same status');
        $this->workflow->batch([$a->id, $b->id], TransactionStatus::Approved, $this->admin);
    }

    public function test_a_batch_is_all_or_nothing(): void
    {
        $a = $this->spels(TransactionStatus::Forwarded);

        try {
            $this->workflow->batch([$a->id, 999999], TransactionStatus::Received, $this->admin);
            $this->fail('A missing record should have stopped the batch.');
        } catch (WorkflowException) {
            $this->assertSame(TransactionStatus::Forwarded, $a->fresh()->transaction_status);
        }
    }

    public function test_a_batch_only_takes_the_batch_statuses(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->batch([$this->cert(TransactionStatus::Received)->id], TransactionStatus::Completed, $this->admin);
    }

    public function test_a_batch_cannot_apply_a_status_the_type_does_not_allow(): void
    {
        $cert = $this->cert(TransactionStatus::Received);

        $this->expectException(WorkflowException::class);
        $this->workflow->batch([$cert->id], TransactionStatus::Approved, $this->admin);
    }

    public function test_two_rival_applications_cannot_be_approved_in_the_same_batch(): void
    {
        $a = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['office_id' => 2, 'transaction_status' => TransactionStatus::Received]);
        $b = Transaction::factory()->forApplicant(' CRUZ ', 'ana', 'Reyes', 'HGE')->create(['office_id' => 1, 'transaction_status' => TransactionStatus::Received]);

        try {
            $this->workflow->batch([$a->id, $b->id], TransactionStatus::Approved, $this->admin);
            $this->fail('The rival applications should have blocked the batch.');
        } catch (WorkflowException $e) {
            $this->assertSame('spels_duplicate', $e->reason);
        }

        $this->assertSame(0, Transaction::where('transaction_status', TransactionStatus::Approved)->count());
    }

    // ─── revert ───────────────────────────────────────────────────────────

    public function test_an_administrator_can_revert_a_record_and_the_old_status_is_audited(): void
    {
        $record = $this->spels(TransactionStatus::Received);

        $this->workflow->revert($record, TransactionStatus::Forwarded, $this->admin);

        $this->assertSame(TransactionStatus::Forwarded, $record->fresh()->transaction_status);
        $this->assertSame('received', AuditLog::where('subject_id', $record->id)->first()->before['status']);
    }

    public function test_a_record_cannot_be_reverted_to_a_status_its_type_does_not_have(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->revert($this->cert(TransactionStatus::Released), TransactionStatus::Forwarded, $this->admin);
    }

    public function test_reverting_to_the_current_status_is_refused(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow->revert($this->spels(TransactionStatus::Received), TransactionStatus::Received, $this->admin);
    }

    // ─── what the screen offers ───────────────────────────────────────────

    public function test_the_offered_actions_follow_the_flow(): void
    {
        $labels = fn (Transaction $t) => array_column($this->workflow->availableActions($t->load('office')), 'label');

        $this->assertSame(['Forward to RO'], $labels($this->spels(TransactionStatus::Submitted)));
        $this->assertSame(['Mark received'], $labels($this->spels(TransactionStatus::Forwarded)));
        $this->assertSame(['Approve', 'Disapprove', 'Return to applicant'], $labels($this->spels(TransactionStatus::Received)));
        $this->assertSame(['Mark completed', 'Cancel transaction'], $labels($this->cert(TransactionStatus::Received)));
        $this->assertSame(['Mark released'], $labels($this->cert(TransactionStatus::Completed)));
        $this->assertSame([], $labels($this->cert(TransactionStatus::Released)));
        $this->assertSame(['Mark approved and released'], $labels($this->spels(TransactionStatus::Approved)));
    }
}
