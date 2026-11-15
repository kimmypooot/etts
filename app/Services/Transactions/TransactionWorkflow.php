<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Spels\DuplicateCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to a transaction status goes through here, so the rules live in
 * one place and the screens, the batch bar and (later) the field office portal
 * cannot disagree.
 *
 * Status flow:
 *  - SPELS:  submitted -> forwarded -> received -> Approved | Disapproved | Returned,
 *            then Approved -> Approved/Released.
 *  - Certification, Authentication, both: submitted -> received -> Completed
 *            -> Released, wherever it was filed. It is received once paid, that
 *            is once it carries an OR number (the recorder does this when the
 *            number is saved). Cancelled only from submitted or received.
 *  - A SPELS field-office filing is forwarded and then received. It is forwarded
 *    only once paid (it carries an OR number); until then it stays submitted at
 *    the office. A SPELS filing made at ESD itself has no forwarding leg, so it
 *    is received straight from submitted, also only once paid.
 *  - A SPELS application may be Returned before it is received too (submitted
 *    or forwarded): that is the SPELS equivalent of cancelling it.
 *
 * Which buttons a board shows is {@see TransactionActions}' job; it asks this
 * class what is legal (canForward, canReceive, canMove, previousStatus).
 *
 * Every change runs in a transaction on a locked row and writes one audit entry
 * per record. Approving a SPELS record passes the one-approval-per-eligibility
 * guard first. Authorisation (who may do this) is the caller's job, through
 * the policy; this class only enforces what the data allows.
 */
final class TransactionWorkflow
{
    /** The statuses a batch may apply. */
    public const BATCH_TARGETS = [
        TransactionStatus::Received,
        TransactionStatus::Approved,
        TransactionStatus::Disapproved,
        TransactionStatus::Returned,
    ];

    /** The statuses an administrator may revert a record to. */
    public const REVERT_TARGETS = [
        TransactionStatus::Submitted,
        TransactionStatus::Forwarded,
        TransactionStatus::Received,
        TransactionStatus::Completed,
    ];

    /** A reason is required when a transaction leaves the happy path. */
    private const NEEDS_REMARKS = [
        TransactionStatus::Disapproved,
        TransactionStatus::Returned,
        TransactionStatus::Cancelled,
    ];

    public function __construct(private DuplicateCheck $duplicates) {}

    /**
     * Sends a submitted transaction on to the Regional Office.
     */
    public function forward(Transaction $transaction, string $date, User $actor): Transaction
    {
        return $this->locked($transaction, function (Transaction $locked) use ($date, $actor) {
            // A returned application is closed: the applicant files a new one.
            if ($locked->transaction_status !== TransactionStatus::Submitted) {
                throw new WorkflowException("Only a submitted application can be forwarded; this one is {$locked->transaction_status->value}.");
            }

            if ($locked->transaction_type->isIssuance()) {
                throw new WorkflowException('Certifications and authentications are received at ESD directly and are not forwarded.');
            }

            if ($locked->office?->isEsd()) {
                throw new WorkflowException('An application filed at ESD is already at the Regional Office and is not forwarded; mark it received instead.');
            }

            if (! self::isPaid($locked)) {
                throw new WorkflowException('Enter the OR number first: an application is forwarded to the Regional Office only once it is paid.');
            }

            $this->apply($locked, TransactionStatus::Forwarded, ['date_sent_to_ro' => $date], $actor, "Forwarded to the Regional Office on {$date}.");

            return $locked;
        });
    }

    /**
     * Marks a transaction as received by the action officer at ESD.
     */
    public function receive(Transaction $transaction, string $date, User $actor): Transaction
    {
        return $this->locked($transaction, function (Transaction $locked) use ($date, $actor) {
            $this->assertCanReceive($locked);
            $this->apply($locked, TransactionStatus::Received, ['date_received_ro' => $date, ...$this->officer($actor)], $actor, "Received by the action officer on {$date}.");

            return $locked;
        });
    }

    /**
     * Moves a received transaction to its next stage (Completed, Released,
     * Approved, Approved/Released, Disapproved, Returned or Cancelled).
     */
    public function changeStatus(Transaction $transaction, TransactionStatus $to, User $actor, ?string $date = null, ?string $remarks = null): Transaction
    {
        return $this->locked($transaction, function (Transaction $locked) use ($to, $actor, $date, $remarks) {
            $this->assertCanMove($locked, $to);

            $remarks = trim((string) $remarks);
            if ($remarks === '' && in_array($to, self::NEEDS_REMARKS, true)) {
                throw new WorkflowException('A reason or remark is required when returning, disapproving, or cancelling a transaction.');
            }

            $date ??= today()->toDateString();
            $attributes = ['date_completed' => $date, ...$this->officer($actor)];

            if ($remarks !== '') {
                $entry = "[{$date} {$to->value}]: {$remarks}";
                $attributes['remarks'] = filled($locked->remarks) ? $locked->remarks."\n".$entry : $entry;
            }

            $this->apply($locked, $to, $attributes, $actor, "Status set to {$to->value}.".($remarks !== '' ? " Remarks: {$remarks}" : ''));

            return $locked;
        });
    }

    /**
     * Applies one status to several records at once. The records must all be in
     * the same status, and each must be allowed to make the move on its own.
     * All or nothing.
     *
     * @param  list<int>  $ids
     */
    public function batch(array $ids, TransactionStatus $to, User $actor): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw new WorkflowException('No records selected for the batch update.');
        }

        if (! in_array($to, self::BATCH_TARGETS, true)) {
            throw new WorkflowException('That status cannot be applied to a batch.');
        }

        return DB::transaction(function () use ($ids, $to, $actor) {
            $records = Transaction::query()->with('office')->whereIn('id', $ids)->lockForUpdate()->get();

            if ($records->count() !== count($ids)) {
                throw new WorkflowException('Some of the selected records could not be found or are no longer available.');
            }

            if ($records->pluck('transaction_status')->unique()->count() > 1) {
                throw new WorkflowException('Batch action denied: the selected records do not all have the same status.');
            }

            foreach ($records as $record) {
                $to === TransactionStatus::Received ? $this->assertCanReceive($record) : $this->assertCanMove($record, $to);
            }

            if ($to->isSpelsApproved()) {
                $this->assertBatchHasNoRivals($records->all());
            }

            $today = today()->toDateString();
            $column = $to === TransactionStatus::Received ? 'date_received_ro' : 'date_completed';

            foreach ($records as $record) {
                $this->apply($record, $to, [$column => $today, ...$this->officer($actor)], $actor,
                    "Batch: status set to {$to->value} (1 of {$records->count()} in this batch).", ['batch' => $records->count()]);
            }

            return $records->count();
        });
    }

    /**
     * Sends a record back to an earlier stage. For administrators correcting a
     * mistake; the old status is kept in the audit entry.
     */
    public function revert(Transaction $transaction, TransactionStatus $to, User $actor): Transaction
    {
        return $this->locked($transaction, function (Transaction $locked) use ($to, $actor) {
            // Certifications and authentications are never forwarded, so they cannot go back to it.
            $neverHad = $locked->transaction_type->isIssuance() && $to === TransactionStatus::Forwarded;

            if ($neverHad || ! in_array($to, self::REVERT_TARGETS, true) || ! in_array($to, TransactionStatus::allowedFor($locked->transaction_type), true)) {
                throw new WorkflowException("A {$locked->transaction_type->value} cannot be reverted to {$to->value}.");
            }

            if ($locked->transaction_status === $to) {
                throw new WorkflowException("The transaction is already {$to->value}.");
            }

            $this->apply($locked, $to, [], $actor, "Reverted to {$to->value}.", ['reverted' => true]);

            return $locked;
        });
    }

    // ─── Rules ────────────────────────────────────────────────────────────

    /**
     * Whether the record carries proof of payment: an OR number that is not
     * blank. A field office application is forwarded only once it is paid.
     */
    public static function isPaid(Transaction $transaction): bool
    {
        return filled($transaction->official_receipt);
    }

    /**
     * Whether the record can be forwarded to the Regional Office now: a
     * submitted, paid SPELS application filed at a field office. (ESD is the
     * Regional Office: what it files itself is received, not forwarded. A
     * returned application is closed.)
     */
    public function canForward(Transaction $transaction): bool
    {
        return $transaction->transaction_status === TransactionStatus::Submitted
            && $transaction->transaction_type->isSpels()
            && ! ($transaction->office?->isEsd() ?? false)
            && self::isPaid($transaction);
    }

    /**
     * Whether ESD would receive the record once it is paid: a certification
     * or authentication still submitted, a SPELS application forwarded to it,
     * or one filed at ESD itself and still submitted.
     */
    public function awaitsReceipt(Transaction $transaction): bool
    {
        $status = $transaction->transaction_status;

        if ($transaction->transaction_type->isIssuance()) {
            return $status === TransactionStatus::Submitted;
        }

        return $status === TransactionStatus::Forwarded
            || ($status === TransactionStatus::Submitted && ($transaction->office?->isEsd() ?? false));
    }

    /** Whether the record can be received now: it awaits receipt and it is paid. */
    public function canReceive(Transaction $transaction): bool
    {
        return $this->awaitsReceipt($transaction) && self::isPaid($transaction);
    }

    /** Whether `$to` is a legal next status for the record now. */
    public function canMove(Transaction $transaction, TransactionStatus $to): bool
    {
        return $this->moveError($transaction, $to) === null;
    }

    /**
     * The status one step back in a certification or authentication's flow,
     * for the board's Revert: Released goes back to Completed, Completed to
     * Received. Null when there is no single step back.
     */
    public static function previousStatus(Transaction $transaction): ?TransactionStatus
    {
        if (! $transaction->transaction_type->isIssuance()) {
            return null;
        }

        return match ($transaction->transaction_status) {
            TransactionStatus::Released => TransactionStatus::Completed,
            TransactionStatus::Completed => TransactionStatus::Received,
            default => null,
        };
    }

    private function assertCanReceive(Transaction $transaction): void
    {
        if ($this->awaitsReceipt($transaction) && ! self::isPaid($transaction)) {
            throw new WorkflowException('Enter the OR number first: a transaction is received only once it is paid.');
        }

        if (! $this->canReceive($transaction)) {
            throw new WorkflowException($transaction->transaction_type->isIssuance()
                ? 'Only a submitted certification or authentication can be received.'
                : "Only a forwarded transaction can be received; this one is {$transaction->transaction_status->value}.");
        }
    }

    private function assertCanMove(Transaction $transaction, TransactionStatus $to): void
    {
        $error = $this->moveError($transaction, $to);

        if ($error !== null) {
            throw new WorkflowException($error);
        }
    }

    /**
     * Why this record cannot move to `$to`, or null when it can.
     */
    private function moveError(Transaction $transaction, TransactionStatus $to): ?string
    {
        $from = $transaction->transaction_status;

        if (! in_array($to, TransactionStatus::allowedFor($transaction->transaction_type), true) || in_array($to, TransactionStatus::flow(), true)) {
            return "A {$transaction->transaction_type->value} cannot be set to {$to->value}.";
        }

        return match ($to) {
            TransactionStatus::Completed => $from === TransactionStatus::Received ? null : 'Invalid flow: the transaction must be received before it can be marked completed.',
            TransactionStatus::Released => $from === TransactionStatus::Completed ? null : 'Invalid flow: the transaction must be completed before it can be released.',
            TransactionStatus::Cancelled => in_array($from, [TransactionStatus::Submitted, TransactionStatus::Received], true) ? null : 'Invalid flow: only a submitted or received transaction can be cancelled.',
            TransactionStatus::Approved, TransactionStatus::Disapproved => $from === TransactionStatus::Received ? null : 'Invalid flow: the transaction must be received before this action.',
            // Returning a SPELS application is also how one is withdrawn before it is received.
            TransactionStatus::Returned => in_array($from, [TransactionStatus::Submitted, TransactionStatus::Forwarded, TransactionStatus::Received], true) ? null : 'Invalid flow: only a submitted, forwarded or received application can be returned.',
            TransactionStatus::ApprovedReleased => $from === TransactionStatus::Approved ? null : 'Invalid flow: the transaction must be approved before it can be released.',
            default => 'That status change is not allowed.',
        };
    }

    /**
     * Two pending applications for the same applicant and eligibility each pass
     * the single-record guard (neither is approved yet), so a batch must also be
     * checked against itself or it would leave two approved rows.
     *
     * @param  list<Transaction>  $records
     */
    private function assertBatchHasNoRivals(array $records): void
    {
        $seen = [];

        foreach ($records as $record) {
            if (! $record->transaction_type->isSpels() || blank($record->elig_code)) {
                continue;
            }

            $key = Str::lower(Str::ascii(implode('|', array_map(fn ($part) => trim((string) $part), [$record->lname, $record->fname, $record->mname, $record->elig_code]))));

            if (isset($seen[$key])) {
                throw new WorkflowException(
                    'This batch contains more than one application for the same applicant under eligibility "'.trim($record->elig_code).'" '
                    ."(records #{$seen[$key]} and #{$record->id}). An applicant may hold only one approved record per eligibility, so they cannot be approved together.",
                    'spels_duplicate',
                );
            }

            $seen[$key] = $record->id;
        }
    }

    // ─── Plumbing ─────────────────────────────────────────────────────────

    /**
     * The action officer is the ESD staff member who works the record. A field
     * office user (forwarding, or recording a payment that auto-receives) or
     * cancelling their own filing never becomes it.
     *
     * @return array{action_officer?: string}
     */
    private function officer(User $actor): array
    {
        return $actor->isAdmin() ? ['action_officer' => $actor->username] : [];
    }

    /**
     * Runs `$work` on a freshly locked copy of the record.
     *
     * @template T
     *
     * @param  callable(Transaction): T  $work
     * @return T
     */
    private function locked(Transaction $transaction, callable $work): mixed
    {
        return DB::transaction(function () use ($transaction, $work) {
            $locked = Transaction::query()->with('office')->whereKey($transaction->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new WorkflowException('The transaction no longer exists.');
            }

            return $work($locked);
        });
    }

    /**
     * Writes the new status and its dates, guards approvals, and records the audit entry.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $extra
     */
    private function apply(Transaction $transaction, TransactionStatus $to, array $attributes, User $actor, string $summary, array $extra = []): void
    {
        if ($to->isSpelsApproved()) {
            $block = $this->duplicates->blocksApproval($transaction->id, $to);

            if ($block->blocked) {
                throw new WorkflowException($this->duplicates->message($block), 'spels_duplicate');
            }
        }

        $from = $transaction->transaction_status;

        $transaction->forceFill([...$attributes, 'transaction_status' => $to])->save();

        AuditLog::record('transaction.status_changed', $transaction,
            before: ['status' => $from->value],
            after: ['status' => $to->value, 'summary' => $summary, ...$extra],
            user: $actor,
        );
    }
}
