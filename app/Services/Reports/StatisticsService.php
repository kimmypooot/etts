<?php

namespace App\Services\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two statistics reports, over a date range and optionally one office.
 *
 *  - Certifications and authentications, one row per type. The range applies to
 *    the date the request was filed.
 *  - Special eligibility applications, one row per eligibility. Here each
 *    column has its own date (filed, forwarded, received, decided), and a
 *    record counts in a column when THAT date falls in the range, so a
 *    January application approved in February shows in February's approvals.
 *
 * Every column is defined once, as a SQL condition, and used both to count a
 * row's cells and to list the records behind a cell (drill-down). The count
 * and the list therefore cannot disagree.
 */
final class StatisticsService
{
    public const COE_AUTH = 'coe_auth';

    public const SPELS = 'spels';

    private const UNSPECIFIED = 'Unspecified';

    /**
     * The columns of a report: key => label.
     *
     * @return array<string, string>
     */
    public static function columns(string $category): array
    {
        return $category === self::SPELS
            ? ['submitted' => 'Filed', 'forwarded' => 'Forwarded', 'received' => 'Received', 'approved' => 'Approved', 'disapproved' => 'Disapproved', 'returned' => 'Returned', 'pending' => 'Pending']
            : ['filed' => 'Filed', 'received' => 'Received', 'completed' => 'Completed', 'released' => 'Released', 'cancelled' => 'Cancelled', 'pending' => 'Pending'];
    }

    public static function category(?string $value): string
    {
        return $value === self::COE_AUTH ? self::COE_AUTH : self::SPELS;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, columns: array<string, string>, officers?: list<array{label: string, value: int}>, sexes?: list<array{label: string, value: int}>}
     */
    public function report(string $category, string $from, string $to, ?int $office): array
    {
        $columns = self::columns($category);
        $select = [];
        $bindings = [];

        foreach (array_keys($columns) as $key) {
            [$sql, $params] = $this->condition($category, $key, $from, $to);
            $select[] = "coalesce(sum(case when {$sql} then 1 else 0 end), 0) as {$key}";
            array_push($bindings, ...$params);
        }

        $group = $this->groupExpression($category);

        $rows = $this->base($category, $from, $to, $office)
            ->selectRaw("{$group} as grp, ".implode(', ', $select), $bindings)
            ->groupBy('grp')->orderBy('grp')
            ->get()
            ->map(function ($row) use ($columns) {
                $out = ['group' => (string) $row->grp];
                foreach (array_keys($columns) as $key) {
                    $out[$key] = (int) $row->{$key};
                }

                return $out;
            })->all();

        $totals = [];
        foreach (array_keys($columns) as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        $report = ['rows' => $rows, 'totals' => $totals, 'columns' => $columns];

        if ($category === self::COE_AUTH) {
            $report['officers'] = $this->released($from, $to, $office, "coalesce(nullif(action_officer, ''), 'Unassigned')", 10);
            $report['sexes'] = $this->released($from, $to, $office, "coalesce(nullif(sex, ''), 'Not recorded')", 5);
        }

        return $report;
    }

    /**
     * The records behind one cell of a report (or a whole column, when `$group`
     * is null), newest first.
     */
    public function details(string $category, string $metric, ?string $group, string $from, string $to, ?int $office, int $perPage = 25): LengthAwarePaginator
    {
        abort_unless(array_key_exists($metric, self::columns($category)), 404);

        [$sql, $params] = $this->condition($category, $metric, $from, $to);

        return $this->base($category, $from, $to, $office)
            ->whereRaw($sql, $params)
            ->when($group !== null && $group !== '', fn (Builder $query) => $this->inGroup($query, $category, $group))
            ->with('office:id,code')
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * The people who released the most, or the sexes of those released.
     *
     * @return list<array{label: string, value: int}>
     */
    private function released(string $from, string $to, ?int $office, string $expression, int $limit): array
    {
        return $this->base(self::COE_AUTH, $from, $to, $office)
            ->where('transaction_status', TransactionStatus::Released->value)
            ->selectRaw("{$expression} as label, count(*) as total")->groupBy('label')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (int) $row->total])->all();
    }

    /**
     * The records that belong to the report at all, before any column.
     *
     * @return Builder<Transaction>
     */
    private function base(string $category, string $from, string $to, ?int $office): Builder
    {
        $query = Transaction::query()->when($office, fn (Builder $q, int $id) => $q->where('office_id', $id));

        if ($category === self::COE_AUTH) {
            return $query
                ->whereIn('transaction_type', array_map(fn (TransactionType $t) => $t->value, TransactionType::issuance()))
                ->whereBetween('transaction_date', [$from, $to]);
        }

        // A SPELS record is in the report if any of its four dates is in the range.
        return $query->where('transaction_type', TransactionType::Spels->value)->where(fn (Builder $q) => $q
            ->whereBetween('transaction_date', [$from, $to])
            ->orWhereBetween('date_sent_to_ro', [$from, $to])
            ->orWhereBetween('date_received_ro', [$from, $to])
            ->orWhereBetween('date_completed', [$from, $to]));
    }

    private function groupExpression(string $category): string
    {
        return $category === self::COE_AUTH ? 'transaction_type' : "coalesce(nullif(elig_code, ''), '".self::UNSPECIFIED."')";
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function inGroup(Builder $query, string $category, string $group): Builder
    {
        if ($category === self::COE_AUTH) {
            return $query->where('transaction_type', $group);
        }

        return $group === self::UNSPECIFIED
            ? $query->where(fn (Builder $q) => $q->whereNull('elig_code')->orWhere('elig_code', ''))
            : $query->where('elig_code', $group);
    }

    /**
     * One column's SQL condition and its bindings.
     *
     * @return array{string, list<mixed>}
     */
    private function condition(string $category, string $metric, string $from, string $to): array
    {
        $status = fn (TransactionStatus ...$statuses) => [
            'transaction_status in ('.implode(',', array_fill(0, count($statuses), '?')).')',
            array_map(fn (TransactionStatus $s) => $s->value, $statuses),
        ];

        if ($category === self::COE_AUTH) {
            return match ($metric) {
                'filed' => ['1 = 1', []],
                'received' => ['date_received_ro is not null', []],
                'completed' => ['date_completed is not null', []],
                'released' => $status(TransactionStatus::Released),
                'cancelled' => $status(TransactionStatus::Cancelled),
                // Received but not finished: not completed, released or cancelled.
                'pending' => [
                    'date_received_ro is not null and date_completed is null and transaction_status not in (?, ?, ?)',
                    [TransactionStatus::Cancelled->value, TransactionStatus::Released->value, TransactionStatus::Completed->value],
                ],
            };
        }

        $window = fn (string $column) => ["({$column} is not null and {$column} between ? and ?)", [$from, $to]];
        $decided = function (TransactionStatus ...$statuses) use ($status, $window) {
            [$inWindow, $windowParams] = $window('date_completed');
            [$inStatus, $statusParams] = $status(...$statuses);

            return ["{$inWindow} and {$inStatus}", [...$windowParams, ...$statusParams]];
        };

        return match ($metric) {
            'submitted' => ['(transaction_date between ? and ?)', [$from, $to]],
            'forwarded' => $window('date_sent_to_ro'),
            'received' => $window('date_received_ro'),
            'approved' => $decided(TransactionStatus::Approved, TransactionStatus::ApprovedReleased),
            'disapproved' => $decided(TransactionStatus::Disapproved),
            'returned' => $decided(TransactionStatus::Returned),
            // Received in the range and still waiting for a decision.
            'pending' => (function () use ($window, $status) {
                [$inWindow, $windowParams] = $window('date_received_ro');
                [$inStatus, $statusParams] = $status(TransactionStatus::Received);

                return ["{$inWindow} and {$inStatus}", [...$windowParams, ...$statusParams]];
            })(),
        };
    }
}
