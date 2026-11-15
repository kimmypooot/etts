<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessStepRequest;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Transactions\ProcessSteps;
use App\Services\Transactions\TransactionRecorder;
use App\Services\Transactions\WorkflowException;
use App\Support\ProcessPage;
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
    public function __construct(private TransactionRecorder $recorder) {}

    private function presenter(): TransactionFormPresenter
    {
        return new TransactionFormPresenter('/admin/transactions', 'attachments.show');
    }

    public function show(Request $request, Transaction $transaction): Response
    {
        abort_unless($transaction->transaction_type->isIssuance(), 404);

        $step = $request->query('step');

        return Inertia::render('Transactions/Process', ProcessPage::props($transaction, $this->presenter(), $step === null ? null : (int) $step));
    }

    public function save(ProcessStepRequest $request, Transaction $transaction, int $step): RedirectResponse
    {
        abort_unless($transaction->transaction_type->isIssuance(), 404);

        if (ProcessSteps::isLocked($transaction)) {
            return back()->with('error', "This record is {$transaction->transaction_status->value} and can no longer be edited. Revert it first to correct it.");
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
