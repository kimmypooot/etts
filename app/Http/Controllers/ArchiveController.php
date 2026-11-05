<?php

namespace App\Http\Controllers;

use App\Enums\DeletionReason;
use App\Models\DeletedTransaction;
use App\Models\Office;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The deleted-records archive: read-only, filtered and paged in SQL.
 */
class ArchiveController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'reason' => DeletionReason::tryFrom((string) $request->query('reason'))?->value,
            'office' => $request->integer('office') ?: null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $offices = Office::pluck('code', 'id');

        $records = DeletedTransaction::query()
            ->when($filters['reason'], fn (Builder $query, string $reason) => $query->where('deletion_reason_code', $reason))
            ->when($filters['office'], fn (Builder $query, int $office) => $query->where('office_id', $office))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->whereDate('deleted_at', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->whereDate('deleted_at', '<=', $to))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';

                $query->where(function (Builder $inner) use ($like, $filters) {
                    $inner->where('lname', 'like', $like)->orWhere('fname', 'like', $like)->orWhere('official_receipt', 'like', $like);

                    if (ctype_digit($filters['search'])) {
                        $inner->orWhere('id', (int) $filters['search']);
                    }
                });
            })
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DeletedTransaction $record) => [
                'id' => $record->id,
                'name' => trim($record->lname.', '.trim($record->fname.' '.($record->mname ?? ''))),
                'type' => $record->transaction_type,
                'status' => $record->transaction_status,
                'office' => $offices[$record->office_id] ?? null,
                'transaction_date' => $record->transaction_date->toDateString(),
                'deleted_at' => $record->deleted_at->toIso8601String(),
                'deleted_by' => $record->deleted_by_name,
                'reason' => $record->reason()?->label() ?? $record->deletion_reason_code,
                'note' => $record->deletion_reason_note,
            ]);

        return Inertia::render('Archive/Index', [
            'records' => $records,
            'filters' => $filters,
            'options' => [
                'reasons' => array_map(fn (DeletionReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], DeletionReason::cases()),
                'offices' => $offices->map(fn ($code, $id) => ['value' => $id, 'label' => $code])->values()->all(),
            ],
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
