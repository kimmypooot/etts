<?php

namespace App\Http\Controllers;

use App\Enums\DeletionReason;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Office;
use App\Models\Transaction;
use App\Services\Transactions\BoardFilters;
use App\Services\Transactions\TransactionWorkflow;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ESD transaction board. Two boards share one screen: Active (work still to
 * do) and History (finished). The split follows
 * {@see TransactionStatus::boardActive()}; Completed stays Active until it is
 * released. Filtering, sorting and paging all happen in SQL, through
 * {@see BoardFilters} (shared with the CSV export).
 */
class TransactionController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, TransactionWorkflow $workflow): Response
    {
        $filters = BoardFilters::fromRequest($request);

        $transactions = BoardFilters::sorted(BoardFilters::query($filters)->with('office:id,name,code'), $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'name' => $this->fullName($transaction),
                'type' => $transaction->transaction_type->value,
                'status' => $transaction->transaction_status->value,
                'office' => $transaction->office?->code,
                'eligibility' => $transaction->elig_code,
                'official_receipt' => $transaction->official_receipt,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'date_sent_to_ro' => $transaction->date_sent_to_ro?->toDateString(),
                'date_received_ro' => $transaction->date_received_ro?->toDateString(),
                'date_completed' => $transaction->date_completed?->toDateString(),
                'transaction_details' => $transaction->transaction_details,
                'remarks' => $transaction->remarks,
                'action_officer' => $transaction->action_officer,
                'actions' => $workflow->availableActions($transaction),
            ]);

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'counts' => [
                'active' => Transaction::whereIn('transaction_status', BoardFilters::statuses('active'))->count(),
                'history' => Transaction::whereIn('transaction_status', BoardFilters::statuses('history'))->count(),
            ],
            'options' => [
                'types' => array_map(fn (TransactionType $type) => ['value' => $type->value, 'label' => $type->value], TransactionType::cases()),
                'statuses' => array_map(fn (string $status) => ['value' => $status, 'label' => $status], BoardFilters::statuses($filters['board'])),
                'offices' => Office::orderBy('name')->get(['id', 'code'])->map(fn (Office $office) => ['value' => $office->id, 'label' => $office->code])->all(),
                'deletionReasons' => array_map(fn (DeletionReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], DeletionReason::cases()),
                'noteMin' => DeletionReason::NOTE_MIN,
                'noteMax' => DeletionReason::NOTE_MAX,
                'batchTargets' => array_map(fn (TransactionStatus $status) => $status->value, TransactionWorkflow::BATCH_TARGETS),
                'revertTargets' => array_map(fn (TransactionStatus $status) => $status->value, TransactionWorkflow::REVERT_TARGETS),
            ],
        ]);
    }

    private function fullName(Transaction $transaction): string
    {
        $given = trim($transaction->fname.' '.($transaction->mname ?? '').' '.($transaction->extn ?? ''));

        return trim($transaction->lname.', '.$given);
    }
}
