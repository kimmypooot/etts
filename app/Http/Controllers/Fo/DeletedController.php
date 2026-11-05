<?php

namespace App\Http\Controllers\Fo;

use App\Enums\DeletionReason;
use App\Http\Controllers\Controller;
use App\Models\DeletedTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The deleted records of the signed-in field office: read-only, filtered and
 * paged in SQL. (The model has no office scope, so the office is applied here.)
 */
class DeletedController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $officeId = $request->user()->office_id;

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'reason' => DeletionReason::tryFrom((string) $request->query('reason'))?->value,
            'office' => null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';

        $records = DeletedTransaction::query()
            ->where('office_id', $officeId)
            ->when($filters['reason'], fn (Builder $query, string $reason) => $query->where('deletion_reason_code', $reason))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->whereDate('deleted_at', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->whereDate('deleted_at', '<=', $to))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('lname', 'like', $like)->orWhere('fname', 'like', $like)->orWhere('official_receipt', 'like', $like)
                ->when(ctype_digit($filters['search']), fn (Builder $q) => $q->orWhere('id', (int) $filters['search']))))
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (DeletedTransaction $record) => [
                'id' => $record->id,
                'name' => trim($record->lname.', '.trim($record->fname.' '.($record->mname ?? ''))),
                'type' => $record->transaction_type,
                'status' => $record->transaction_status,
                'office' => null,
                'transaction_date' => $record->transaction_date->toDateString(),
                'deleted_at' => $record->deleted_at->toIso8601String(),
                'deleted_by' => $record->deleted_by_name,
                'reason' => $record->reason()?->label() ?? $record->deletion_reason_code,
                'note' => $record->deletion_reason_note,
            ]);

        return Inertia::render('Archive/Index', [
            'base' => '/fo/deleted',
            'timelineBase' => '/fo/transactions',
            'records' => $records,
            'filters' => $filters,
            'options' => [
                'reasons' => array_map(fn (DeletionReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], DeletionReason::cases()),
                'offices' => [],
            ],
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
