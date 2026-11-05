<?php

namespace App\Http\Controllers;

use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Models\User;
use App\Services\Transactions\WorkflowException;
use App\Services\Transmittals\TransmittalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transmittal batches for ESD: the list (both directions), building a batch for
 * one field office, editing a draft, releasing it and printing its routing slip.
 * The rules are in {@see TransmittalService}. The field office portal extends
 * this controller and changes only what differs (see the protected hooks).
 */
class TransmittalController extends Controller
{
    protected const PER_PAGE = 25;

    public function __construct(protected TransmittalService $service) {}

    // ─── What differs between ESD and the field office portal ─────────────

    /** URL prefix of this portal's transmittal screens. */
    protected function base(): string
    {
        return '/admin/transmittals';
    }

    protected function indexRoute(): string
    {
        return 'transmittals.index';
    }

    protected function showRoute(): string
    {
        return 'transmittals.show';
    }

    protected function portal(): string
    {
        return 'esd';
    }

    protected function defaultDirection(): TransmittalDirection
    {
        return TransmittalDirection::EsdToFo;
    }

    /**
     * Which batches the list may show. ESD sees everything.
     *
     * @param  Builder<Transmittal>  $query
     * @return Builder<Transmittal>
     */
    protected function visible(Builder $query): Builder
    {
        return $query;
    }

    protected function canCreate(User $user): bool
    {
        return $user->can('manage', Transmittal::class);
    }

    /**
     * What this person may do with this batch. A released or transmitted batch
     * allows nothing but printing.
     *
     * @return array{edit: bool, release: bool, transmit: bool, delete: bool}
     */
    protected function capabilities(Transmittal $transmittal, User $user): array
    {
        $edit = $transmittal->isDraft() && $user->can('manage', $transmittal);

        return [
            'edit' => $edit,
            'release' => $edit && $transmittal->direction === TransmittalDirection::EsdToFo,
            'transmit' => false,
            'delete' => $edit,
        ];
    }

    // ─── Screens ──────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $filters = [
            'direction' => TransmittalDirection::tryFrom((string) $request->query('direction'))?->value ?? $this->defaultDirection()->value,
            'search' => trim((string) $request->query('search', '')),
            'status' => TransmittalStatus::tryFrom((string) $request->query('status'))?->value,
            'office' => $request->integer('office') ?: null,
        ];

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';

        $transmittals = $this->visible(Transmittal::query())
            ->with(['targetOffice:id,code,name', 'originOffice:id,code,name'])
            ->withCount('items')
            ->where('direction', $filters['direction'])
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['office'], fn (Builder $query, int $office) => $query->where(fn (Builder $inner) => $inner->where('target_office_id', $office)->orWhere('origin_office_id', $office)))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('transmittal_no', 'like', $like)->orWhere('remarks', 'like', $like)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transmittal $transmittal) => $this->summary($transmittal));

        return Inertia::render('Transmittals/Index', [
            'portal' => $this->portal(),
            'base' => $this->base(),
            'canCreate' => $this->canCreate($request->user()),
            'transmittals' => $transmittals,
            'filters' => $filters,
            'counts' => [
                TransmittalDirection::EsdToFo->value => $this->visible(Transmittal::query())->where('direction', TransmittalDirection::EsdToFo->value)->count(),
                TransmittalDirection::FoToRo->value => $this->visible(Transmittal::query())->where('direction', TransmittalDirection::FoToRo->value)->count(),
            ],
            'options' => [
                'statuses' => array_map(fn (TransmittalStatus $status) => ['value' => $status->value, 'label' => $status->value], TransmittalStatus::cases()),
                'offices' => Office::orderBy('name')->get(['id', 'code'])->map(fn (Office $office) => ['value' => $office->id, 'label' => $office->code])->all(),
            ],
        ]);
    }

    /**
     * Step one picks the field office (with how many transactions are waiting
     * for each); step two lists that office's eligible transactions.
     */
    public function create(Request $request): Response
    {
        $officeId = $request->integer('office') ?: null;
        $search = trim((string) $request->query('search', ''));

        $waiting = $this->service->eligible(TransmittalDirection::EsdToFo)
            ->selectRaw('office_id, count(*) as waiting')->groupBy('office_id')->pluck('waiting', 'office_id');

        $offices = Office::orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Office $office) => ['id' => $office->id, 'code' => $office->code, 'name' => $office->name, 'waiting' => (int) ($waiting[$office->id] ?? 0)])
            ->filter(fn (array $office) => $office['waiting'] > 0)
            ->values()->all();

        $records = null;
        if ($officeId !== null) {
            $records = $this->eligibleRecords($this->service->eligible(TransmittalDirection::EsdToFo)->where('office_id', $officeId), $search, 100);
        }

        return Inertia::render('Transmittals/Create', [
            'offices' => $offices,
            'office' => $officeId,
            'search' => $search,
            'records' => $records,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.TransmittalService::MAX_RECORDS],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $transmittal = $this->service->createDraft(TransmittalDirection::EsdToFo, $data['ids'], (string) ($data['remarks'] ?? ''), $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->showRoute(), $transmittal)->with('status', "Transmittal {$transmittal->transmittal_no} created as a draft.");
    }

    public function show(Request $request, Transmittal $transmittal): Response
    {
        $transmittal->load(['targetOffice:id,code,name', 'originOffice:id,code,name']);

        return Inertia::render('Transmittals/Show', [
            'portal' => $this->portal(),
            'base' => $this->base(),
            'transmittal' => $this->summary($transmittal) + ['remarks' => $transmittal->remarks, 'draft' => $transmittal->isDraft()],
            'capabilities' => $this->capabilities($transmittal, $request->user()),
            'items' => $this->service->describeItems($transmittal),
        ]);
    }

    /**
     * The transactions that can still be added to this draft, for its picker.
     */
    public function eligible(Request $request, Transmittal $transmittal): JsonResponse
    {
        $query = $this->service->eligible($transmittal->direction)->where('office_id', $transmittal->fieldOfficeId());

        return response()->json($this->eligibleRecords($query, trim((string) $request->query('search', '')), 15));
    }

    // ─── Editing a draft ──────────────────────────────────────────────────

    public function addRecord(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $data = $request->validate(['transaction_id' => ['required', 'integer']]);

        return $this->run(fn () => $this->service->addRecord($transmittal, $data['transaction_id'], $request->user()), 'Transaction added.');
    }

    public function addManual(Request $request, Transmittal $transmittal): RedirectResponse
    {
        return $this->run(fn () => $this->service->addManual($transmittal, $this->manualInput($request), $request->user()), 'Document added.');
    }

    public function updateManual(Request $request, Transmittal $transmittal, int $item): RedirectResponse
    {
        return $this->run(fn () => $this->service->updateManual($transmittal, $item, $this->manualInput($request), $request->user()), 'Document updated.');
    }

    public function removeItem(Request $request, Transmittal $transmittal, int $item): RedirectResponse
    {
        return $this->run(fn () => $this->service->removeItem($transmittal, $item, $request->user()), 'Row removed.');
    }

    public function remarks(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:200']]);

        return $this->run(fn () => $this->service->updateRemarks($transmittal, (string) ($data['remarks'] ?? ''), $request->user()), 'Remarks saved.');
    }

    public function note(Request $request, Transmittal $transmittal, int $transaction): RedirectResponse
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(['transaction_details', 'remarks'])],
            'value' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(fn () => $this->service->updateRecordNote($transmittal, $transaction, $data['field'], (string) ($data['value'] ?? ''), $request->user()), 'Saved.');
    }

    public function release(Request $request, Transmittal $transmittal): RedirectResponse
    {
        return $this->run(fn () => $this->service->release($transmittal, $request->user()), "{$transmittal->transmittal_no} released.");
    }

    public function destroy(Request $request, Transmittal $transmittal): RedirectResponse
    {
        try {
            $this->service->delete($transmittal, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->indexRoute())->with('status', "Draft {$transmittal->transmittal_no} deleted.");
    }

    /**
     * The routing slip, laid out for printing. Its wording and signatory are
     * defaults the person printing can change on screen.
     */
    public function slip(Transmittal $transmittal): Response
    {
        $transmittal->load(['targetOffice:id,code,name', 'originOffice:id,code,name']);
        $key = $transmittal->direction === TransmittalDirection::EsdToFo ? 'esd_to_fo' : 'fo_to_ro';

        return Inertia::render('Transmittals/Slip', [
            'base' => $this->base(),
            'transmittal' => $this->summary($transmittal) + ['remarks' => $transmittal->remarks],
            'items' => $this->service->describeItems($transmittal),
            'defaults' => config("etts.transmittal.{$key}") + ['date' => today()->format('F j, Y')]
                // The signing officer differs at each field office, so it starts as the person printing (as in legacy).
                + ($key === 'fo_to_ro' ? ['signatory' => (string) auth()->user()?->name] : []),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param  callable(): mixed  $action
     */
    protected function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $success);
    }

    /**
     * @return array{description: ?string, doc_type?: ?string, name: ?string, remarks?: ?string, type?: ?string}
     */
    protected function manualInput(Request $request): array
    {
        $data = $request->validate([
            'manual_name' => ['required', 'string', 'max:150'],
            'manual_description' => ['required', 'string', 'max:255'],
            'manual_doc_type' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:200'],
        ]);

        return [
            'name' => $data['manual_name'],
            'description' => $data['manual_description'],
            'type' => $data['manual_doc_type'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    protected function eligibleRecords(Builder $query, string $search, int $limit): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $query->when($search !== '', fn (Builder $inner) => $inner->where(fn (Builder $q) => $q
            ->where('lname', 'like', $like)->orWhere('fname', 'like', $like)->orWhere('elig_code', 'like', $like)
            ->when(ctype_digit($search), fn (Builder $q2) => $q2->orWhere('id', (int) $search))));

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'data' => $query->orderByDesc('id')->limit($limit)->get()->map(fn (Transaction $record) => [
                'id' => $record->id,
                'name' => trim($record->lname.', '.$record->fname),
                'type' => $record->transaction_type->value,
                'status' => $record->transaction_status->value,
                'eligibility' => $record->elig_code,
                'date' => $record->transaction_date->toDateString(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Transmittal $transmittal): array
    {
        return [
            'id' => $transmittal->id,
            'number' => $transmittal->transmittal_no,
            'direction' => $transmittal->direction->value,
            'status' => $transmittal->status->value,
            'office' => $transmittal->direction === TransmittalDirection::FoToRo ? $transmittal->originOffice?->only('id', 'code', 'name') : $transmittal->targetOffice?->only('id', 'code', 'name'),
            'created_at' => $transmittal->created_at?->toIso8601String(),
            'created_by' => $transmittal->created_by_name ?? ($transmittal->created_by_imis_id ? 'User #'.$transmittal->created_by_imis_id : null),
            'items_count' => $transmittal->items_count ?? null,
        ];
    }
}
