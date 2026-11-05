<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transactions\TransactionWorkflow;
use App\Services\Transactions\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moves transactions along their status flow. All rules live in
 * {@see TransactionWorkflow}; this only validates the request, authorises it
 * and turns a refusal into a message the person can read.
 */
class TransactionStatusController extends Controller
{
    public function __construct(private TransactionWorkflow $workflow) {}

    public function forward(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->run(fn () => $this->workflow->forward($transaction, $data['date'], $request->user()), 'Forwarded to the Regional Office.');
    }

    public function receive(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return $this->run(fn () => $this->workflow->receive($transaction, $data['date'], $request->user()), 'Marked as received.');
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TransactionStatus::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = TransactionStatus::from($data['status']);

        return $this->run(
            fn () => $this->workflow->changeStatus($transaction, $status, $request->user(), $data['date'] ?? null, $data['remarks'] ?? null),
            "Status set to {$status->value}.",
        );
    }

    public function revert(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(TransactionStatus::class)]]);

        $status = TransactionStatus::from($data['status']);

        return $this->run(fn () => $this->workflow->revert($transaction, $status, $request->user()), "Reverted to {$status->value}.");
    }

    public function batch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::enum(TransactionStatus::class)],
        ]);

        $status = TransactionStatus::from($data['status']);

        try {
            $count = $this->workflow->batch($data['ids'], $status, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$count} transaction(s) set to {$status->value}.");
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $success);
    }
}
