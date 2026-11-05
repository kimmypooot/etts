<?php

namespace App\Http\Controllers\Fo;

use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use App\Http\Controllers\TransmittalController as BaseTransmittalController;
use App\Models\Transmittal;
use App\Models\User;
use App\Services\Transactions\WorkflowException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The field office's transmittals: the batches of SPELS applications it sends up
 * to the Regional Office, and the released batches ESD sends down to it. Same
 * rules and pages as ESD ({@see BaseTransmittalController}); this changes who
 * may do what, and builds a batch from the office's own applications.
 */
class TransmittalController extends BaseTransmittalController
{
    protected function base(): string
    {
        return '/fo/transmittals';
    }

    protected function indexRoute(): string
    {
        return 'fo.transmittals.index';
    }

    protected function showRoute(): string
    {
        return 'fo.transmittals.show';
    }

    protected function portal(): string
    {
        return 'fo';
    }

    protected function defaultDirection(): TransmittalDirection
    {
        return TransmittalDirection::FoToRo;
    }

    protected function canCreate(User $user): bool
    {
        return $user->can('office', Transmittal::class);
    }

    /**
     * A batch ESD is still preparing is not the office's to see.
     */
    protected function visible(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('direction', TransmittalDirection::FoToRo->value)
            ->orWhere('status', '!=', TransmittalStatus::Draft->value));
    }

    protected function capabilities(Transmittal $transmittal, User $user): array
    {
        $edit = $transmittal->isDraft() && $user->can('workAsOffice', $transmittal);

        return ['edit' => $edit, 'release' => false, 'transmit' => $edit, 'delete' => $edit];
    }

    /**
     * The office's new applications and corrected returned ones that are free to
     * send up, with room for other documents.
     */
    public function create(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $officeId = $request->user()->office_id;

        $records = $this->eligibleRecords(
            $this->service->eligible(TransmittalDirection::FoToRo)->where('office_id', $officeId),
            $search,
            100,
        );

        return Inertia::render('Fo/Transmittals/Create', ['search' => $search, 'records' => $records]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:'.\App\Services\Transmittals\TransmittalService::MAX_RECORDS],
            'ids.*' => ['integer'],
            'manual' => ['nullable', 'array', 'max:50'],
            'manual.*.manual_name' => ['required', 'string', 'max:150'],
            'manual.*.manual_description' => ['required', 'string', 'max:255'],
            'manual.*.manual_doc_type' => ['nullable', 'string', 'max:100'],
            'manual.*.remarks' => ['nullable', 'string', 'max:200'],
            'remarks' => ['nullable', 'string', 'max:200'],
        ]);

        $manual = array_map(fn (array $row) => [
            'name' => $row['manual_name'],
            'description' => $row['manual_description'],
            'type' => $row['manual_doc_type'] ?? null,
            'remarks' => $row['remarks'] ?? null,
        ], $data['manual'] ?? []);

        try {
            $transmittal = $this->service->createDraft(
                TransmittalDirection::FoToRo,
                $data['ids'] ?? [],
                (string) ($data['remarks'] ?? ''),
                $request->user(),
                $request->user()->office_id,
                $manual,
            );
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->showRoute(), $transmittal)->with('status', "Transmittal {$transmittal->transmittal_no} saved as a draft.");
    }

    /**
     * Sends the batch up: its applications become forwarded, dated as given
     * (today when none is).
     */
    public function transmit(Request $request, Transmittal $transmittal): RedirectResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return $this->run(
            fn () => $this->service->transmit($transmittal, $data['date'] ?? today()->toDateString(), $request->user()),
            "{$transmittal->transmittal_no} transmitted to the Regional Office.",
        );
    }
}
