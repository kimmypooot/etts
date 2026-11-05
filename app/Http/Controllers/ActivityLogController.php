<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LegacyActivityLog;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The activity log for administrators: what this app recorded ("current") and
 * the history imported from the old system ("legacy"). Two sources, so two
 * lists, each filtered and paged in SQL.
 */
class ActivityLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        if ($filters['source'] === 'legacy') {
            $entries = $this->legacyQuery($filters)
                ->paginate(self::PER_PAGE)->withQueryString()
                ->through(fn (LegacyActivityLog $log) => $this->legacyRow($log));

            $actions = LegacyActivityLog::query()->distinct()->orderBy('action_type')->pluck('action_type');
        } else {
            $entries = $this->currentQuery($filters)
                ->paginate(self::PER_PAGE)->withQueryString()
                ->through(fn (AuditLog $log) => $this->currentRow($log));

            $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');
        }

        return Inertia::render('Activity/Index', [
            'entries' => $entries,
            'filters' => $filters,
            'actions' => $actions,
        ]);
    }

    /**
     * The full audit trail (the tab and filters you are looking at, every
     * page) as CSV. Usernames and what they did leave the system here, so it
     * is restricted by policy and itself audited.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $source = $filters['source'];

        AuditLog::record('activity.exported', null, after: ['summary' => "Exported the {$source} activity log to CSV.", 'filters' => $filters]);

        $query = $source === 'legacy' ? $this->legacyQuery($filters) : $this->currentQuery($filters);

        return response()->streamDownload(function () use ($query, $source) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
            fputcsv($out, ['Log ID', 'Date & Time', 'User', 'Action', 'Details', 'Transaction']);

            $query->chunk(500, function ($logs) use ($out, $source) {
                foreach ($logs as $log) {
                    $row = $source === 'legacy' ? $this->legacyRow($log) : $this->currentRow($log);
                    $when = $row['at'] ? \Illuminate\Support\Carbon::parse($row['at'])->timezone(config('app.timezone'))->format('Y-m-d H:i:s') : '';

                    fputcsv($out, array_map($this->safe(...), [$row['id'], $when, $row['who'], $row['action'], $row['details'], $row['record_id']]));
                }
            });

            fclose($out);
        }, 'activity-'.$source.'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{source: string, search: string, action: string, from: ?string, to: ?string} */
    private function filters(Request $request): array
    {
        return [
            'source' => $request->query('source') === 'legacy' ? 'legacy' : 'current',
            'search' => trim((string) $request->query('search', '')),
            'action' => trim((string) $request->query('action', '')),
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];
    }

    private function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }

    private function legacyQuery(array $filters): Builder
    {
        $like = $this->like($filters['search']);

        return LegacyActivityLog::query()
            ->when($filters['action'] !== '', fn (Builder $query) => $query->where('action_type', $filters['action']))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('details', 'like', $like)->orWhere('username', 'like', $like)))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    private function currentQuery(array $filters): Builder
    {
        $like = $this->like($filters['search']);

        return AuditLog::query()
            ->with('user:id,name,username')
            ->when($filters['action'] !== '', fn (Builder $query) => $query->where('action', $filters['action']))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('after->summary', 'like', $like)
                ->orWhere('action', 'like', $like)
                ->orWhereHas('user', fn (Builder $user) => $user->where('username', 'like', $like)->orWhere('name', 'like', $like))))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    private function legacyRow(LegacyActivityLog $log): array
    {
        return [
            'id' => 'l'.$log->id,
            'at' => $log->created_at?->toIso8601String(),
            'who' => $log->username,
            'action' => $log->action_type,
            'details' => $log->details,
            'record_id' => $log->record_id,
        ];
    }

    private function currentRow(AuditLog $log): array
    {
        return [
            'id' => 'c'.$log->id,
            'at' => $log->created_at?->toIso8601String(),
            'who' => $log->user?->username ?? 'system',
            'action' => $log->action,
            'details' => $log->after['summary'] ?? null,
            'record_id' => $log->subject_type === (new Transaction)->getMorphClass() ? $log->subject_id : null,
        ];
    }

    /** A cell a spreadsheet would run as a formula gets a leading apostrophe. */
    private function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
