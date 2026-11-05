<?php

namespace App\Http\Controllers\Fo;

use App\Enums\DeletionReason;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionFormRequest;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\AttachmentStore;
use App\Services\Transactions\BoardFilters;
use App\Services\Transactions\TransactionArchiver;
use App\Services\Transactions\TransactionRecorder;
use App\Services\Transactions\TransactionWorkflow;
use App\Services\Transactions\WorkflowException;
use App\Support\TransactionFormPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The field office's transactions. Everything here is the office's own: the
 * model's OfficeScope keeps every query to it, and the policy lets the office
 * change a record only while it is still with the office (before the Regional
 * Office has acted). The rules are the ones ESD uses ({@see TransactionRecorder},
 * {@see TransactionWorkflow}, {@see TransactionArchiver}).
 */
class TransactionController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private TransactionRecorder $recorder,
        private TransactionWorkflow $workflow,
        private TransactionArchiver $archiver,
    ) {}

    private function presenter(User $user): TransactionFormPresenter
    {
        return new TransactionFormPresenter('/fo/transactions', 'fo.attachments.show', $user->office_id);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [...BoardFilters::fromRequest($request), 'office' => null];

        $transactions = BoardFilters::sorted(BoardFilters::query($filters), $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'name' => trim($transaction->lname.', '.trim($transaction->fname.' '.($transaction->mname ?? '').' '.($transaction->extn ?? ''))),
                'type' => $transaction->transaction_type->value,
                'status' => $transaction->transaction_status->value,
                'eligibility' => $transaction->elig_code,
                'official_receipt' => $transaction->official_receipt,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'date_sent_to_ro' => $transaction->date_sent_to_ro?->toDateString(),
                'date_received_ro' => $transaction->date_received_ro?->toDateString(),
                'date_completed' => $transaction->date_completed?->toDateString(),
                'remarks' => $transaction->remarks,
                'can_work' => $user->can('workAsOffice', $transaction),
                'can_forward' => $user->can('workAsOffice', $transaction) && $transaction->transaction_type->isSpels(),
            ]);

        return Inertia::render('Fo/Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'counts' => [
                'active' => Transaction::whereIn('transaction_status', BoardFilters::statuses('active'))->count(),
                'history' => Transaction::whereIn('transaction_status', BoardFilters::statuses('history'))->count(),
            ],
            'options' => [
                'types' => array_map(fn (TransactionType $type) => ['value' => $type->value, 'label' => $type->value], TransactionType::cases()),
                'statuses' => array_map(fn (string $status) => ['value' => $status, 'label' => $status], BoardFilters::statuses($filters['board'])),
                'deletionReasons' => array_map(fn (DeletionReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], DeletionReason::cases()),
                'noteMin' => DeletionReason::NOTE_MIN,
                'noteMax' => DeletionReason::NOTE_MAX,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $type = TransactionType::tryFrom((string) $request->query('type')) ?? TransactionType::Spels;

        return Inertia::render('Transactions/Form', $this->presenter($user)->create($type, $user->office_id));
    }

    public function store(TransactionFormRequest $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $transaction = $this->recorder->create(
                [...$request->safe()->except(['attachment_1', 'attachment_2', 'version']), 'office_id' => $user->office_id],
                $user,
                $request->file('attachment_1'),
                $request->file('attachment_2'),
            );
        } catch (WorkflowException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fo.transactions.index')->with('status', "Transaction #{$transaction->id} created.");
    }

    public function edit(Request $request, Transaction $transaction): Response
    {
        return Inertia::render('Transactions/Form', $this->presenter($request->user())->edit($transaction));
    }

    public function update(TransactionFormRequest $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();

        try {
            $this->recorder->update(
                $transaction,
                [...$request->safe()->except(['attachment_1', 'attachment_2', 'version']), 'office_id' => $user->office_id],
                $user,
                $request->input('version'),
                $request->file('attachment_1'),
                $request->file('attachment_2'),
            );
        } catch (WorkflowException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fo.transactions.index')->with('status', "Transaction #{$transaction->id} saved.");
    }

    /**
     * Sends one application up to the Regional Office without a transmittal.
     */
    public function forward(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        try {
            $this->workflow->forward($transaction, $data['date'], $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Forwarded to the Regional Office.');
    }

    /**
     * Deletes the office's own records (into the archive, with a reason) while
     * they are still with the office.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'reason' => ['required', Rule::enum(DeletionReason::class)],
            'note' => ['required', 'string', 'min:'.DeletionReason::NOTE_MIN, 'max:'.DeletionReason::NOTE_MAX],
        ]);

        $user = $request->user();
        $records = Transaction::query()->whereIn('id', $data['ids'])->get();

        if ($records->count() !== count(array_unique($data['ids'])) || $records->contains(fn (Transaction $record) => ! $user->can('workAsOffice', $record))) {
            return back()->with('error', 'Some of the selected records can no longer be deleted by your office. Once the Regional Office has acted on a record, only ESD can delete it.');
        }

        try {
            $count = $this->archiver->archive($data['ids'], DeletionReason::from($data['reason']), $data['note'], $user);
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $count === 1 ? 'Transaction archived and removed.' : "{$count} transactions archived and removed.");
    }

    /**
     * Shows an attachment of the office's own record. Same rules as at ESD:
     * private disk, name from the record, nosniff, every view audited.
     */
    public function attachment(Transaction $transaction, int $slot, AttachmentStore $attachments): HttpResponse
    {
        abort_unless(in_array($slot, [1, 2], true), 404);

        $name = DB::table('issuance_requests')->where('transaction_id', $transaction->id)->value("attachment_{$slot}");

        abort_if($name === null || $name !== basename((string) $name) || ! $attachments->exists($name), 404, 'This attachment is not available.');

        AuditLog::record('attachment.viewed', $transaction, after: ['summary' => "Viewed attachment {$slot}.", 'slot' => $slot, 'file' => $name]);

        return $attachments->response($name);
    }
}
