<?php

namespace App\Services\Transactions;

use App\Enums\DeletionReason;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a transaction moves it, with its issuance request, to the archive
 * (`deleted_transactions`, `deleted_issuance_requests`) together with who,
 * when and why, instead of destroying it. Attachment files stay where they are:
 * the archived issuance request still names them.
 *
 * Every deletion needs a reason from {@see DeletionReason} and a note. All or
 * nothing: if any selected record is missing nothing is deleted.
 */
final class TransactionArchiver
{
    /**
     * @param  list<int>  $ids
     * @return int The number of transactions archived.
     */
    public function archive(array $ids, DeletionReason $reason, string $note, User $actor): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $note = trim($note);

        if ($ids === []) {
            throw new WorkflowException('No records selected for deletion.');
        }

        $length = mb_strlen($note);
        if ($length < DeletionReason::NOTE_MIN || $length > DeletionReason::NOTE_MAX) {
            throw new WorkflowException('Please explain the deletion in '.DeletionReason::NOTE_MIN.' to '.DeletionReason::NOTE_MAX.' characters.');
        }

        return DB::transaction(function () use ($ids, $reason, $note, $actor) {
            $records = Transaction::query()->with('issuanceRequest')->whereIn('id', $ids)->lockForUpdate()->get();

            if ($records->count() !== count($ids)) {
                throw new WorkflowException('Some of the selected records no longer exist, so nothing was deleted.');
            }

            // A record in an open transmittal cannot vanish from under it.
            $inDraft = DB::table('transmittal_items as ti')
                ->join('transmittals as t', 't.id', '=', 'ti.transmittal_id')
                ->whereIn('ti.transaction_id', $ids)
                ->where('t.status', 'Draft')
                ->first(['t.transmittal_no', 'ti.transaction_id']);

            if ($inDraft !== null) {
                throw new WorkflowException("Transaction #{$inDraft->transaction_id} is in the draft transmittal {$inDraft->transmittal_no}. Remove it from the transmittal before deleting it.");
            }

            $deletedAt = now()->toDateTimeString();

            foreach ($records as $record) {
                DB::table('deleted_transactions')->insert([
                    ...$record->getAttributes(),
                    'deleted_at' => $deletedAt,
                    'deleted_by_id' => $actor->imis_user_id,
                    'deleted_by_name' => $actor->username,
                    'deletion_reason_code' => $reason->value,
                    'deletion_reason_note' => $note,
                ]);

                if ($record->issuanceRequest !== null) {
                    DB::table('deleted_issuance_requests')->insert([
                        ...$record->issuanceRequest->getAttributes(),
                        'deleted_at' => $deletedAt,
                        'deleted_by_id' => $actor->imis_user_id,
                    ]);
                }

                AuditLog::record('transaction.deleted', $record,
                    before: ['status' => $record->transaction_status->value, 'type' => $record->transaction_type->value],
                    after: [
                        'summary' => "Deleted. Reason: {$reason->label()} — {$note}".($records->count() > 1 ? " (part of a {$records->count()}-record deletion)" : ''),
                        'reason' => $reason->value,
                    ],
                    user: $actor,
                );

                $record->delete();
            }

            return $records->count();
        });
    }
}
