<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\TransactionFormRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Services\Transactions\TransactionRecorder;
use App\Services\Transactions\WorkflowException;
use App\Support\TransactionFormPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creating and editing a transaction at ESD. The rules (duplicate approval gate,
 * pricing, concurrency, walk-in confirmation) live in {@see TransactionRecorder};
 * the form's data in {@see TransactionFormPresenter}. The field office portal has
 * its own controller over the same pieces.
 */
class TransactionFormController extends Controller
{
    public function __construct(private TransactionRecorder $recorder) {}

    private function presenter(): TransactionFormPresenter
    {
        return new TransactionFormPresenter('/admin/transactions', 'attachments.show');
    }

    public function create(Request $request): Response
    {
        $type = TransactionType::tryFrom((string) $request->query('type')) ?? TransactionType::Spels;
        $esd = (int) (Office::where('code', 'ESD')->value('id') ?? $request->user()->office_id ?? 1);

        return Inertia::render('Transactions/Form', $this->presenter()->create($type, $esd));
    }

    public function store(TransactionFormRequest $request): RedirectResponse
    {
        try {
            $transaction = $this->recorder->create(
                $request->safe()->except(['attachment_1', 'attachment_2', 'version']),
                $request->user(),
                $request->file('attachment_1'),
                $request->file('attachment_2'),
            );
        } catch (WorkflowException $e) {
            return $this->refuse($e);
        }

        return redirect()->route('transactions.index')->with('status', "Transaction #{$transaction->id} created.");
    }

    public function edit(Transaction $transaction): Response
    {
        return Inertia::render('Transactions/Form', $this->presenter()->edit($transaction));
    }

    public function update(TransactionFormRequest $request, Transaction $transaction): RedirectResponse
    {
        try {
            $this->recorder->update(
                $transaction,
                $request->safe()->except(['attachment_1', 'attachment_2', 'version']),
                $request->user(),
                $request->input('version'),
                $request->file('attachment_1'),
                $request->file('attachment_2'),
            );
        } catch (WorkflowException $e) {
            return $this->refuse($e);
        }

        return redirect()->route('transactions.index')->with('status', "Transaction #{$transaction->id} saved.");
    }

    public function quick(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'official_receipt' => ['nullable', 'string', 'max:50', 'regex:/^[\w\-\/# ]*$/u'],
            'transaction_details' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'date_sent_to_ro' => ['nullable', 'date_format:Y-m-d'],
            'date_received_ro' => ['nullable', 'date_format:Y-m-d'],
            'date_completed' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $this->recorder->quickEdit($transaction, $data, $request->user());
        } catch (WorkflowException $e) {
            return $this->refuse($e);
        }

        return back()->with('status', "Transaction #{$transaction->id} updated.");
    }

    private function refuse(WorkflowException $e): RedirectResponse
    {
        return back()->withInput()->with('error', $e->getMessage());
    }
}
