<?php

namespace App\Services\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\TransmittalStatus;
use App\Models\AuditLog;
use App\Models\LegacyActivityLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Support\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The figures on the ESD dashboard. Everything is totals or short lists of the
 * work that needs attention; each figure is one grouped query (the time series
 * group in SQL, not in PHP). All of it follows the signed-in user's office
 * scope, so the same service would serve a field office.
 *
 * "Today" and the month boundaries are Manila time (the app time zone).
 */
final class DashboardMetrics
{
    /** Days a forwarded application may wait to be received before it is flagged. */
    public const RECEIPT_ALERT_DAYS = 3;

    /** Days a transmittal draft may sit before it is flagged as stale. */
    public const DRAFT_ALERT_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'kpis' => $this->kpis(),
            'trends' => $this->trends(),
            'statusMix' => $this->statusMix(),
            'byType' => $this->byType(),
            'topOffices' => $this->topOffices(),
            'topEligibilities' => $this->topEligibilities(),
            'turnaround' => $this->turnaround(),
            'attention' => $this->attention(),
            'activity' => $this->activity(),
        ];
    }

    /**
     * The headline figures, each with how it compares to the period before and
     * a 14-day trail for its sparkline.
     *
     * @return list<array<string, mixed>>
     */
    public function kpis(): array
    {
        $today = today();
        $monthStart = $today->copy()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonth();
        // Month to date against the same days of last month, so a month that is
        // only a few days old is not measured against a whole one.
        $lastMonthSameDay = $lastMonthStart->copy()->addDays(min($today->day, $lastMonthStart->daysInMonth) - 1)->endOfDay();

        $filedPerDay = $this->filedPerDay(14);

        $filedToday = $filedPerDay[$today->toDateString()] ?? 0;
        $filedYesterday = $filedPerDay[$today->copy()->subDay()->toDateString()] ?? 0;


        $revenue = (int) Transaction::whereBetween('transaction_date', [$monthStart, $today->copy()->endOfDay()])->sum('amount');
        $lastRevenue = (int) Transaction::whereBetween('transaction_date', [$lastMonthStart, $lastMonthSameDay])->sum('amount');

        $released = Transaction::whereBetween('doc_release_date', [$monthStart->toDateString(), $today->toDateString()])->count();
        $lastReleased = Transaction::whereBetween('doc_release_date', [$lastMonthStart->toDateString(), $lastMonthSameDay->toDateString()])->count();

        return [
            [
                'key' => 'filed_today', 'label' => 'Filed today', 'value' => $filedToday, 'icon' => 'clipboard', 'tone' => 'primary',
                'delta' => null, 'compare' => "{$filedYesterday} filed yesterday",
                'spark' => array_values($filedPerDay),
                'href' => '/admin/transactions',
            ],
            [
                'key' => 'open', 'label' => 'Open work', 'value' => Transaction::whereIn('transaction_status', TransactionStatus::values(TransactionStatus::boardActive()))->count(),
                'icon' => 'activity', 'tone' => 'info', 'delta' => null, 'compare' => 'on the Active board', 'spark' => null,
                'href' => '/admin/transactions',
            ],
            [
                'key' => 'awaiting_receipt', 'label' => 'Awaiting receipt', 'value' => Transaction::where('transaction_status', TransactionStatus::Forwarded->value)->count(),
                'icon' => 'clock', 'tone' => 'warning', 'delta' => null, 'compare' => 'forwarded, not yet received', 'spark' => null,
                'href' => '/admin/transactions?status=forwarded',
            ],
            [
                'key' => 'ready_release', 'label' => 'Ready to release', 'value' => Transaction::whereIn('transaction_status', TransactionStatus::values([TransactionStatus::Completed, TransactionStatus::Approved]))->count(),
                'icon' => 'envelope', 'tone' => 'success', 'delta' => null, 'compare' => 'completed or approved', 'spark' => null,
                'href' => '/admin/transmittals/create',
            ],
            [
                'key' => 'released_month', 'label' => 'Released this month', 'value' => $released,
                'icon' => 'checkCircle', 'tone' => 'success', 'delta' => $this->delta($released, $lastReleased), 'compare' => 'vs same days last month', 'spark' => null,
                'href' => '/admin/transmittals',
            ],
            [
                'key' => 'revenue_month', 'label' => 'Collected this month', 'value' => $revenue, 'format' => 'peso',
                'icon' => 'tag', 'tone' => 'primary', 'delta' => $this->delta($revenue, $lastRevenue), 'compare' => 'vs same days last month', 'spark' => null,
                'href' => null,
            ],
        ];
    }

    /**
     * Filings over time, split into SPELS and certification/authentication.
     *
     * @return array{daily: array<string, mixed>, monthly: array<string, mixed>}
     */
    public function trends(): array
    {
        $dayFrom = today()->copy()->subDays(29);
        $monthFrom = today()->copy()->startOfMonth()->subMonths(11);

        $daily = $this->series(Sql::day('transaction_date'), $dayFrom, fn ($d) => $d->toDateString(), 30, 'addDay', fn ($d) => $d->format('M j'));
        $monthly = $this->series(Sql::month('transaction_date'), $monthFrom, fn ($d) => $d->format('Y-m'), 12, 'addMonth', fn ($d) => $d->format('M Y'));

        return ['daily' => $daily, 'monthly' => $monthly];
    }

    /**
     * Where all transactions stand, in four groups.
     *
     * @return list<array{key: string, label: string, value: int, color: string}>
     */
    public function statusMix(): array
    {
        $counts = Transaction::query()->selectRaw('transaction_status, count(*) as total')->groupBy('transaction_status')->pluck('total', 'transaction_status');

        $sum = fn (array $statuses) => (int) collect($statuses)->sum(fn (TransactionStatus $status) => $counts[$status->value] ?? 0);

        return [
            ['key' => 'open', 'label' => 'In progress', 'value' => $sum(TransactionStatus::flow()), 'color' => 'info'],
            ['key' => 'ready', 'label' => 'Ready to release', 'value' => $sum([TransactionStatus::Completed, TransactionStatus::Approved]), 'color' => 'warning'],
            ['key' => 'done', 'label' => 'Released', 'value' => $sum([TransactionStatus::Released, TransactionStatus::ApprovedReleased]), 'color' => 'success'],
            ['key' => 'not_granted', 'label' => 'Disapproved, returned or cancelled', 'value' => $sum([TransactionStatus::Disapproved, TransactionStatus::Returned, TransactionStatus::Cancelled]), 'color' => 'danger'],
        ];
    }

    /**
     * Filings by type over the last 12 months.
     *
     * @return list<array{label: string, value: int}>
     */
    public function byType(): array
    {
        $counts = Transaction::query()
            ->where('transaction_date', '>=', today()->copy()->startOfMonth()->subMonths(11))
            ->selectRaw('transaction_type, count(*) as total')->groupBy('transaction_type')->pluck('total', 'transaction_type');

        return collect(TransactionType::cases())
            ->map(fn (TransactionType $type) => ['label' => $type->value, 'value' => (int) ($counts[$type->value] ?? 0)])
            ->all();
    }

    /**
     * The busiest offices over the last 12 months.
     *
     * @return list<array{label: string, value: int}>
     */
    public function topOffices(int $limit = 8): array
    {
        $offices = Office::pluck('code', 'id');

        return Transaction::query()
            ->where('transaction_date', '>=', today()->copy()->startOfMonth()->subMonths(11))
            ->selectRaw('office_id, count(*) as total')->groupBy('office_id')->orderByDesc('total')->limit($limit)->get()
            ->map(fn (Transaction $row) => ['label' => $offices[$row->office_id] ?? "Office {$row->office_id}", 'value' => (int) $row->total])
            ->all();
    }

    /**
     * The eligibilities applied for most over the last 12 months.
     *
     * @return list<array{label: string, value: int}>
     */
    public function topEligibilities(int $limit = 6): array
    {
        return Transaction::query()
            ->where('transaction_type', TransactionType::Spels->value)
            ->where('transaction_date', '>=', today()->copy()->startOfMonth()->subMonths(11))
            ->selectRaw("coalesce(nullif(elig_code, ''), 'Unspecified') as label, count(*) as total")
            ->groupBy('label')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (int) $row->total])
            ->all();
    }

    /**
     * Average days from filing to completion for what was completed in the last
     * 90 days, for each family of transaction.
     *
     * @return array{spels: array{days: ?float, count: int}, issuance: array{days: ?float, count: int}}
     */
    public function turnaround(): array
    {
        $rows = Transaction::query()
            ->whereNotNull('date_completed')
            ->where('date_completed', '>=', today()->copy()->subDays(90)->toDateString())
            ->get(['transaction_type', 'transaction_date', 'date_completed']);

        $days = fn (Collection $group) => $group->isEmpty() ? null : round($group->avg(fn (Transaction $t) => max(0, $t->transaction_date->startOfDay()->diffInDays($t->date_completed->startOfDay()))), 1);

        [$spels, $issuance] = [$rows->filter(fn (Transaction $t) => $t->transaction_type->isSpels()), $rows->reject(fn (Transaction $t) => $t->transaction_type->isSpels())];

        return [
            'spels' => ['days' => $days($spels), 'count' => $spels->count()],
            'issuance' => ['days' => $days($issuance), 'count' => $issuance->count()],
        ];
    }

    /**
     * Work that is waiting too long.
     *
     * @return array{awaitingReceipt: list<array<string, mixed>>, releaseBacklog: list<array<string, mixed>>, staleDrafts: list<array<string, mixed>>}
     */
    public function attention(): array
    {
        $offices = Office::pluck('code', 'id');

        $awaiting = Transaction::query()
            ->where('transaction_status', TransactionStatus::Forwarded->value)
            ->orderBy('date_sent_to_ro')->orderBy('id')->limit(8)->get()
            ->map(function (Transaction $t) use ($offices) {
                $since = ($t->date_sent_to_ro ?? $t->transaction_date)->startOfDay();

                return [
                    'id' => $t->id,
                    'name' => trim($t->lname.', '.$t->fname),
                    'office' => $offices[$t->office_id] ?? null,
                    'days' => (int) $since->diffInDays(today()),
                    'since' => $since->toDateString(),
                ];
            })->all();

        // Documents filed at ESD itself are not sent to a field office, so they are not part of this backlog.
        $esd = Office::where('code', 'ESD')->value('id');

        $backlog = Transaction::query()
            ->when($esd, fn ($query, $id) => $query->where('office_id', '!=', $id))
            ->whereIn('transaction_status', TransactionStatus::values([TransactionStatus::Completed, TransactionStatus::Approved]))
            ->selectRaw('office_id, count(*) as total, min(date_completed) as oldest')->groupBy('office_id')->orderByDesc('total')->limit(6)->get()
            ->map(fn (Transaction $row) => [
                'office' => $offices[$row->office_id] ?? "Office {$row->office_id}",
                'office_id' => $row->office_id,
                'count' => (int) $row->total,
                'oldest' => $row->oldest ? CarbonImmutable::parse($row->oldest)->toDateString() : null,
            ])->all();

        $drafts = Transmittal::query()
            ->where('status', TransmittalStatus::Draft->value)
            ->where('created_at', '<', now()->subDays(self::DRAFT_ALERT_DAYS))
            ->orderBy('created_at')->limit(5)->get()
            ->map(fn (Transmittal $t) => [
                'id' => $t->id,
                'number' => $t->transmittal_no,
                'direction' => $t->direction->value,
                'office' => $offices[$t->fieldOfficeId()] ?? null,
                'days' => (int) $t->created_at->startOfDay()->diffInDays(today()),
            ])->all();

        return ['awaitingReceipt' => $awaiting, 'releaseBacklog' => $backlog, 'staleDrafts' => $drafts];
    }

    /**
     * The latest things that happened, from this system and, when quiet, from
     * the imported history.
     *
     * @return list<array{at: ?string, who: ?string, action: string, details: ?string, record_id: ?int}>
     */
    public function activity(int $limit = 8): array
    {
        $current = AuditLog::query()->with('user:id,username')->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'who' => $log->user?->username ?? 'system',
                'action' => $log->action,
                'details' => $log->after['summary'] ?? null,
                'record_id' => $log->subject_type === (new Transaction)->getMorphClass() ? $log->subject_id : null,
            ]);

        $legacy = LegacyActivityLog::query()->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (LegacyActivityLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'who' => $log->username,
                'action' => $log->action_type,
                'details' => $log->details,
                'record_id' => $log->record_id,
            ]);

        return $current->concat($legacy)->sortByDesc('at')->take($limit)->values()->all();
    }

    // ─── Plumbing ─────────────────────────────────────────────────────────

    /**
     * Filings per day for the last `$days` days, zero-filled, keyed by date.
     *
     * @return array<string, int>
     */
    private function filedPerDay(int $days): array
    {
        $from = today()->copy()->subDays($days - 1);
        $found = Transaction::query()->where('transaction_date', '>=', $from)
            ->selectRaw(Sql::day('transaction_date').' as bucket, count(*) as total')->groupByRaw(Sql::day('transaction_date'))->pluck('total', 'bucket');

        $out = [];
        for ($d = $from->copy(); $d->lte(today()); $d->addDay()) {
            $out[$d->toDateString()] = (int) ($found[$d->toDateString()] ?? 0);
        }

        return $out;
    }

    /**
     * A zero-filled series split into SPELS and certification/authentication.
     *
     * @param  callable(\Illuminate\Support\Carbon): string  $key
     * @param  callable(\Illuminate\Support\Carbon): string  $label
     * @return array{labels: list<string>, spels: list<int>, issuance: list<int>, total: int}
     */
    private function series(string $bucket, \Illuminate\Support\Carbon $from, callable $key, int $points, string $step, callable $label): array
    {
        $rows = Transaction::query()->where('transaction_date', '>=', $from)
            ->selectRaw("{$bucket} as bucket, sum(case when transaction_type = ? then 1 else 0 end) as spels, sum(case when transaction_type <> ? then 1 else 0 end) as issuance", [TransactionType::Spels->value, TransactionType::Spels->value])
            ->groupByRaw($bucket)->get()->keyBy('bucket');

        $labels = $spels = $issuance = [];
        $cursor = $from->copy();

        for ($i = 0; $i < $points; $i++) {
            $row = $rows[$key($cursor)] ?? null;
            $labels[] = $label($cursor);
            $spels[] = (int) ($row->spels ?? 0);
            $issuance[] = (int) ($row->issuance ?? 0);
            $cursor->{$step}();
        }

        return ['labels' => $labels, 'spels' => $spels, 'issuance' => $issuance, 'total' => array_sum($spels) + array_sum($issuance)];
    }

    private function delta(int|float $now, int|float $before): ?float
    {
        if ($before == 0) {
            return $now == 0 ? 0.0 : null;
        }

        return round((($now - $before) / $before) * 100, 1);
    }
}
