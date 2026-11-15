<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;

/**
 * The buttons a transaction board shows on a row, by portal (ESD or field
 * office), transaction type, status and payment. This is the one place the
 * "action buttons by office and status" matrix lives; both boards render the
 * list as given.
 *
 * Each action is one of:
 *  - `link`:    opens a page (`href`): Process, Receive, View Details.
 *  - `forward`: posts a date to `url` (Forward to RO).
 *  - `status`:  posts `status`, an optional date and remarks to `url`
 *               (Cancel, Return, Approve, Disapprove, Mark as Released).
 *  - `revert`:  posts `status` to `url` after a confirmation (Revert).
 *  - `history`: opens the record's Logs/History window. Always last.
 *
 * Whether a move is legal still comes from {@see TransactionWorkflow}, and
 * every route is authorised by its policy; this only decides what to offer.
 */
final class TransactionActions
{
    public function __construct(private TransactionWorkflow $workflow) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Transaction $transaction, User $actor): array
    {
        $actions = $actor->isAdmin()
            ? $this->esd($transaction, '/admin/transactions/'.$transaction->id)
            : $this->fieldOffice($transaction, '/fo/transactions/'.$transaction->id);

        $actions[] = ['key' => 'history', 'kind' => 'history', 'label' => 'Logs/History'];

        return array_values(array_filter($actions));
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function esd(Transaction $transaction, string $url): array
    {
        $status = $transaction->transaction_status;

        if ($transaction->transaction_type->isIssuance()) {
            return match ($status) {
                TransactionStatus::Submitted, TransactionStatus::Received => [
                    $this->link('process', 'Process', "{$url}/process", 'primary'),
                    $this->status($transaction, 'cancel', 'Cancel', TransactionStatus::Cancelled, "{$url}/status", 'outline', withDate: true),
                ],
                TransactionStatus::Completed => [
                    $this->status($transaction, 'release', 'Mark as Released', TransactionStatus::Released, "{$url}/status", 'primary', withDate: true),
                    $this->link('view', 'View Details', "{$url}/process"),
                    $this->revert($transaction, $url),
                ],
                TransactionStatus::Released => [
                    $this->link('view', 'View Details', "{$url}/process"),
                    $this->revert($transaction, $url),
                ],
                default => [$this->link('view', 'View Details', "{$url}/process")],
            };
        }

        // SPELS. Waiting for ESD to receive it (filed here, or forwarded by an office).
        if ($this->workflow->awaitsReceipt($transaction)) {
            return [
                $this->link('receive', 'Receive', "{$url}/edit", 'primary'),
                $this->status($transaction, 'return', 'Return', TransactionStatus::Returned, "{$url}/status", 'outline', withDate: true),
            ];
        }

        return match ($status) {
            // Still at the field office: ESD may send a paid one up on the office's behalf.
            TransactionStatus::Submitted => [
                $this->workflow->canForward($transaction) ? $this->forward("{$url}/forward") : null,
            ],
            TransactionStatus::Received => [
                $this->status($transaction, 'approve', 'Approve', TransactionStatus::Approved, "{$url}/status", 'primary', withDate: true),
                $this->status($transaction, 'disapprove', 'Disapprove', TransactionStatus::Disapproved, "{$url}/status", 'outline', withDate: true),
                $this->status($transaction, 'return', 'Return', TransactionStatus::Returned, "{$url}/status", 'outline', withDate: true),
            ],
            default => [$this->link('view', 'View Details', "{$url}/edit")],
        };
    }

    /**
     * @return list<array<string, mixed>|null>
     */
    private function fieldOffice(Transaction $transaction, string $url): array
    {
        $submitted = $transaction->transaction_status === TransactionStatus::Submitted;

        if ($transaction->transaction_type->isIssuance()) {
            return $submitted
                ? [
                    // Receive: the processing page, where the office adds the attachments.
                    $this->link('receive', 'Receive', "{$url}/process", 'primary'),
                    $this->status($transaction, 'cancel', 'Cancel', TransactionStatus::Cancelled, "{$url}/cancel", 'outline'),
                ]
                : [$this->link('view', 'View Details', $url)];
        }

        if (! $submitted) {
            return [$this->link('view', 'View Details', $url)];
        }

        // Submitted SPELS: unpaid, the office takes the payment (Receive) or returns it;
        // paid, it goes up to the Regional Office.
        return TransactionWorkflow::isPaid($transaction)
            ? [$this->forward("{$url}/forward")]
            : [
                $this->link('receive', 'Receive', "{$url}/edit", 'primary'),
                $this->status($transaction, 'return', 'Return', TransactionStatus::Returned, "{$url}/return", 'outline'),
            ];
    }

    // ─── Builders ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function link(string $key, string $label, string $href, string $variant = 'secondary'): array
    {
        return ['key' => $key, 'kind' => 'link', 'label' => $label, 'href' => $href, 'variant' => $variant];
    }

    /** @return array<string, mixed> */
    private function forward(string $url): array
    {
        return ['key' => 'forward', 'kind' => 'forward', 'label' => 'Forward to RO', 'url' => $url, 'variant' => 'primary', 'needs_date' => true, 'needs_remarks' => false];
    }

    /** @return array<string, mixed>|null */
    private function status(Transaction $transaction, string $key, string $label, TransactionStatus $to, string $url, string $variant, bool $withDate = false): ?array
    {
        if (! $this->workflow->canMove($transaction, $to)) {
            return null;
        }

        return [
            'key' => $key,
            'kind' => 'status',
            'label' => $label,
            'status' => $to->value,
            'url' => $url,
            'variant' => $variant,
            'needs_date' => $withDate,
            'needs_remarks' => in_array($to, [TransactionStatus::Cancelled, TransactionStatus::Returned, TransactionStatus::Disapproved], true),
        ];
    }

    /** @return array<string, mixed>|null */
    private function revert(Transaction $transaction, string $url): ?array
    {
        $to = TransactionWorkflow::previousStatus($transaction);

        return $to === null ? null : [
            'key' => 'revert',
            'kind' => 'revert',
            'label' => 'Revert',
            'status' => $to->value,
            'url' => "{$url}/revert",
            'variant' => 'outline',
        ];
    }
}
