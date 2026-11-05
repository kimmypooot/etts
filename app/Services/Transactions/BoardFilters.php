<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The transaction board's filters, read from a request and applied in SQL. One
 * definition shared by the screen and the CSV export, so what you export is
 * exactly what you were looking at.
 */
final class BoardFilters
{
    /**
     * @return array{board: string, search: string, type: ?string, status: ?string, office: ?int, from: ?string, to: ?string, sort: string, dir: string}
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'board' => $request->query('board') === 'history' ? 'history' : 'active',
            'search' => trim((string) $request->query('search', '')),
            'type' => TransactionType::tryFrom((string) $request->query('type'))?->value,
            'status' => TransactionStatus::tryFrom((string) $request->query('status'))?->value,
            'office' => $request->integer('office') ?: null,
            'from' => self::date($request->query('from')),
            'to' => self::date($request->query('to')),
            'sort' => in_array($request->query('sort'), ['date', 'name', 'status'], true) ? $request->query('sort') : 'date',
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * The statuses on a board. Active is work still to do; History is finished work.
     *
     * @return list<string>
     */
    public static function statuses(string $board): array
    {
        return TransactionStatus::values($board === 'history' ? TransactionStatus::boardArchive() : TransactionStatus::boardActive());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public static function query(array $filters): Builder
    {
        $search = $filters['search'];

        return Transaction::query()
            ->whereIn('transaction_status', $filters['status'] ? [$filters['status']] : self::statuses($filters['board']))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('transaction_type', $type))
            ->when($filters['office'], fn (Builder $query, int $office) => $query->where('office_id', $office))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->whereDate('transaction_date', '<=', $to))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(function (Builder $inner) use ($like, $search) {
                    $inner->where('lname', 'like', $like)
                        ->orWhere('fname', 'like', $like)
                        ->orWhere('mname', 'like', $like)
                        ->orWhere('official_receipt', 'like', $like)
                        ->orWhere('elig_code', 'like', $like)
                        ->orWhereRaw("concat_ws(', ', lname, fname) like ?", [$like]);

                    if (ctype_digit($search)) {
                        $inner->orWhere('id', (int) $search);
                    }
                });
            });
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public static function sorted(Builder $query, array $filters): Builder
    {
        match ($filters['sort']) {
            'name' => $query->orderBy('lname', $filters['dir'])->orderBy('fname', $filters['dir']),
            'status' => $query->orderBy('transaction_status', $filters['dir']),
            default => $query->orderBy('transaction_date', $filters['dir']),
        };

        return $query->orderByDesc('id');
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
