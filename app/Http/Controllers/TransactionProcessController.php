<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Http\Requests\ProcessStepRequest;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Transactions\ProcessSteps;
use App\Services\Transactions\TransactionRecorder;
use App\Services\Transactions\VcasLink;
use App\Services\Transactions\WorkflowException;
use App\Support\TransactionFormPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ESD processing page for a certification or authentication: five steps
 * that save one at a time, then print, VCAS, Mark completed and Release.
 * Rules live in {@see ProcessSteps}; saving goes through the recorder and
 * status moves through the workflow, the same as everywhere else.
 */
class TransactionProcessController extends Controller
{
    private const LABELS = [1 => 'Personal info', 2 => 'Eligibility', 3 => 'Verification', 4 => 'Payment & files', 5 => 'Print & finish'];

    public function __construct(private TransactionRecorder $recorder) {}

    private function presenter(): TransactionFormPresenter
    {
        return new TransactionFormPresenter('/admin/transactions', 'attachments.show');
    }

    public function show(Request $request, Transaction $transaction): Response
    {
        abort_unless($transaction->transaction_type->isIssuance(), 404);

        $form = $this->presenter()->edit($transaction->loadMissing('office'));
        $record = $form['record'];
        $locked = ProcessSteps::isLocked($transaction);
        // A locked record cannot be saved, so a blank field must not keep it
        // from printing or releasing: every step is open, defaulting to step 5.
        $first = $locked ? 5 : ProcessSteps::firstIncompleteStep($record);
        $requested = (int) $request->query('step', $first);

        return Inertia::render('Transactions/Process', [
            ...collect($form)->only(['id', 'status', 'version', 'record', 'attachments', 'options', 'base'])->all(),
            'office' => $transaction->office?->name,
            'steps' => array_map(fn (int $step) => [
                'number' => $step,
                'label' => self::LABELS[$step],
                'missing' => $step < 5 ? ProcessSteps::missing($step, $record) : [],
            ], array_keys(self::LABELS)),
            'step' => $requested >= 1 && $requested <= $first ? $requested : $first,
            'locked' => $locked,
            'finish' => $finish = ProcessSteps::finishAction($transaction),
            'finishStatus' => match ($finish) {
                'complete' => TransactionStatus::Completed->value,
                'release' => TransactionStatus::Released->value,
                default => null,
            },
            'vcasUrl' => str_contains($transaction->transaction_type->value, 'Certification') ? VcasLink::for($record) : null,
        ]);
    }

    public function save(ProcessStepRequest $request, Transaction $transaction, int $step): RedirectResponse
    {
        abort_unless($transaction->transaction_type->isIssuance(), 404);

        if (ProcessSteps::isLocked($transaction)) {
            return back()->with('error', 'This record is completed or closed and can no longer be edited. Revert it first to correct it.');
        }

        $values = [
            ...$this->presenter()->edit($transaction)['record'],
            ...$request->safe()->only(ProcessSteps::fields($step)),
        ];

        // Only step 4 prices the record: without copies the recorder keeps the
        // stored amount and details, so steps 1–3 never reprice behind the processor's back.
        if ($step !== 4) {
            unset($values['coe_copies'], $values['auth_copies']);
        }

        try {
            $this->recorder->update(
                $transaction,
                $values,
                $request->user(),
                $request->input('version'),
                $step === 4 ? $request->file('attachment_1') : null,
                $step === 4 ? $request->file('attachment_2') : null,
            );
        } catch (WorkflowException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('transactions.process', [$transaction, 'step' => $step + 1])->with('status', "Step {$step} saved.");
    }

    public function vcas(Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->transaction_type->isIssuance(), 404);

        AuditLog::record('transaction.vcas_sent', $transaction, after: ['summary' => 'Sent the record to VCAS.']);

        return back();
    }
}
