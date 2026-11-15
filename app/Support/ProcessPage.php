<?php

namespace App\Support;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transactions\ProcessSteps;
use App\Services\Transactions\VcasLink;

/**
 * The props of the issuance processing page (Transactions/Process), for the
 * ESD page and the field office's read-only view of the same record.
 *
 * The ESD page has five steps, the last one printing and finishing. The field
 * office view has steps 1 to 4 only, all open and read-only: no saving, no
 * printing, no VCAS, no status moves.
 */
final class ProcessPage
{
    private const LABELS = [1 => 'Personal info', 2 => 'Eligibility', 3 => 'Verification', 4 => 'Payment & files', 5 => 'Print & finish'];

    /**
     * @return array<string, mixed>
     */
    public static function props(Transaction $transaction, TransactionFormPresenter $presenter, ?int $requestedStep, bool $viewOnly = false): array
    {
        $form = $presenter->edit($transaction->loadMissing('office'), $viewOnly ? true : null);
        $record = $form['record'];
        $locked = $form['readOnly'];
        $labels = $viewOnly ? array_slice(self::LABELS, 0, 4, true) : self::LABELS;
        $last = array_key_last($labels);

        // A locked record cannot be saved, so a blank field must not keep it
        // from printing or releasing: every step is open. ESD lands on the
        // last step (print and finish); a view starts at the top.
        $first = $locked ? $last : ProcessSteps::firstIncompleteStep($record);
        $requested = $requestedStep ?? ($viewOnly ? 1 : $first);

        return [
            ...collect($form)->only(['id', 'status', 'version', 'record', 'attachments', 'options', 'base'])->all(),
            'office' => $transaction->office?->name,
            'steps' => array_map(fn (int $step) => [
                'number' => $step,
                'label' => $labels[$step],
                'missing' => $step < 5 ? ProcessSteps::missing($step, $record) : [],
            ], array_keys($labels)),
            'step' => $requested >= 1 && $requested <= $first ? $requested : $first,
            'locked' => $locked,
            'viewOnly' => $viewOnly,
            'finish' => $finish = $viewOnly ? null : ProcessSteps::finishAction($transaction),
            'finishStatus' => match ($finish) {
                'complete' => TransactionStatus::Completed->value,
                'release' => TransactionStatus::Released->value,
                default => null,
            },
            'vcasUrl' => ! $viewOnly && str_contains($transaction->transaction_type->value, 'Certification') ? VcasLink::for($record) : null,
        ];
    }
}
