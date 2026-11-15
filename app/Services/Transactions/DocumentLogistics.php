<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use App\Models\Transaction;
use App\Models\Transmittal;
use Illuminate\Support\Facades\DB;

/**
 * Where a SPELS application's documents are: filed, paid, sent up to the
 * Regional Office, received there, decided, and released back. Shown beside
 * the application's form (the Document Logistics panel). Read-only: the
 * dates come from the record and from the transmittal batches it travelled in.
 */
final class DocumentLogistics
{
    /**
     * @return list<array{key: string, label: string, done: bool, date: ?string, detail: ?string}>
     */
    public static function for(Transaction $transaction): array
    {
        $transaction->loadMissing('office');
        $atEsd = $transaction->office?->isEsd() ?? false;
        $status = $transaction->transaction_status;
        $batches = self::batches($transaction);
        $up = $batches[TransmittalDirection::FoToRo->value] ?? null;
        $down = $batches[TransmittalDirection::EsdToFo->value] ?? null;

        $decided = in_array($status, TransactionStatus::terminal(), true);
        // A returned application may have been withdrawn before it was ever received.
        $received = $transaction->date_received_ro !== null
            || in_array($status, [TransactionStatus::Received, TransactionStatus::Approved, TransactionStatus::ApprovedReleased, TransactionStatus::Disapproved], true);

        $steps = [
            self::step('filed', 'Filed', true, $transaction->transaction_date?->toDateString(), $transaction->office?->name),
            self::step('paid', 'Paid', TransactionWorkflow::isPaid($transaction), $transaction->or_date?->toDateString(),
                TransactionWorkflow::isPaid($transaction) ? 'OR '.$transaction->official_receipt.($transaction->amount ? ' · ₱'.number_format($transaction->amount) : '') : 'Awaiting payment'),
        ];

        // A filing made at ESD is already at the Regional Office: no forwarding leg.
        if (! $atEsd) {
            $forwarded = $transaction->date_sent_to_ro !== null || $received || $status === TransactionStatus::Forwarded;
            $steps[] = self::step('forwarded', 'Forwarded to the RO', $forwarded, $transaction->date_sent_to_ro?->toDateString(),
                $up ? "Transmittal {$up}" : ($forwarded ? null : 'Not yet sent'));
        }

        $steps[] = self::step('received', 'Received at the RO', $received, $transaction->date_received_ro?->toDateString(),
            $received && filled($transaction->action_officer) ? "By {$transaction->action_officer}" : null);

        $steps[] = self::step('decided', 'Decision', $decided, $decided ? $transaction->date_completed?->toDateString() : null,
            $decided ? $status->value : 'Pending');

        if (! $atEsd) {
            $released = $transaction->doc_release_date !== null || filled($transaction->document_status);
            $steps[] = self::step('released', 'Released to the office', $released, $transaction->doc_release_date?->toDateString(),
                $down ? "Transmittal {$down}" : ($released ? $transaction->document_status : null));
        }

        return $steps;
    }

    /**
     * The batch number per direction this record travelled in (handed over
     * batches only, the latest one per direction).
     *
     * @return array<string, string>
     */
    private static function batches(Transaction $transaction): array
    {
        return Transmittal::withoutGlobalScopes()
            ->whereIn('id', DB::table('transmittal_items')->where('transaction_id', $transaction->id)->select('transmittal_id'))
            ->where('status', '!=', TransmittalStatus::Draft->value)
            ->orderBy('created_at')
            ->get(['transmittal_no', 'direction'])
            ->mapWithKeys(fn (Transmittal $batch) => [$batch->direction->value => $batch->transmittal_no])
            ->all();
    }

    /**
     * @return array{key: string, label: string, done: bool, date: ?string, detail: ?string}
     */
    private static function step(string $key, string $label, bool $done, ?string $date, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'done' => $done, 'date' => $date, 'detail' => $detail];
    }
}
