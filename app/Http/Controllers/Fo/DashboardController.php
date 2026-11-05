<?php

namespace App\Http\Controllers\Fo;

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The field office's home: what needs doing, what is with the Regional Office,
 * a six-month volume chart and applications that have waited too long to be
 * forwarded. Everything is the signed-in office's own (the model's OfficeScope).
 */
class DashboardController extends Controller
{
    /** Days a submitted application may wait before it appears in the aging queue. */
    private const AGING_DAYS = 10;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->role === UserRole::FieldOffice, 403);

        if ($user->office_id === null) {
            return Inertia::render('Fo/Dashboard', ['office' => null]);
        }

        $count = fn (array $statuses) => Transaction::whereIn('transaction_status', TransactionStatus::values($statuses))->count();

        return Inertia::render('Fo/Dashboard', [
            'office' => $user->office->only('id', 'code', 'name'),
            'stats' => [
                'pending_forward' => $count([TransactionStatus::Submitted]),
                'returned' => $count([TransactionStatus::Returned]),
                'processing_ro' => $count([TransactionStatus::Forwarded, TransactionStatus::Received]),
                'ready_release' => $count([TransactionStatus::Completed]),
            ],
            'chart' => $this->chart(),
            'matrix' => $this->matrix(),
            'aging' => $this->aging(),
        ]);
    }

    /**
     * Applications and certification requests filed in each of the last six
     * months. Counted in PHP from the few hundred rows an office files in that
     * time, so the query stays portable.
     *
     * @return array{labels: list<string>, spels: list<int>, issuance: list<int>}
     */
    private function chart(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = today()->startOfMonth()->subMonths($i);
            $months[$month->format('Y-m')] = ['label' => $month->format('M'), 'spels' => 0, 'issuance' => 0];
        }

        Transaction::query()
            ->where('transaction_date', '>=', today()->startOfMonth()->subMonths(5))
            ->get(['transaction_date', 'transaction_type'])
            ->each(function (Transaction $transaction) use (&$months) {
                $key = $transaction->transaction_date->format('Y-m');

                if (isset($months[$key])) {
                    $months[$key][$transaction->transaction_type->isSpels() ? 'spels' : 'issuance']++;
                }
            });

        return [
            'labels' => array_column($months, 'label'),
            'spels' => array_column($months, 'spels'),
            'issuance' => array_column($months, 'issuance'),
        ];
    }

    /**
     * Where each kind of transaction stands, for the office. The rows are the
     * type, the columns the stages that matter to the office.
     *
     * @return list<array{type: string, submitted: int, forwarded: int, received: int, completed: int}>
     */
    private function matrix(): array
    {
        $grouped = Transaction::query()
            ->selectRaw('transaction_type, transaction_status, count(*) as total')
            ->groupBy('transaction_type', 'transaction_status')
            ->get()
            ->groupBy(fn (Transaction $row) => $row->transaction_type->value);

        $rows = [];
        foreach ($grouped as $type => $statuses) {
            $by = fn (TransactionStatus $status) => (int) ($statuses->first(fn (Transaction $row) => $row->transaction_status === $status)?->total ?? 0);
            $rows[] = [
                'type' => $type,
                'submitted' => $by(TransactionStatus::Submitted),
                'forwarded' => $by(TransactionStatus::Forwarded),
                'received' => $by(TransactionStatus::Received),
                'completed' => $by(TransactionStatus::Completed),
            ];
        }

        return $rows;
    }

    /**
     * Submitted applications that have waited longer than {@see AGING_DAYS}
     * days to be forwarded, oldest first.
     *
     * @return list<array{id: int, name: string, type: string, days: int, date: string}>
     */
    private function aging(): array
    {
        return Transaction::query()
            ->where('transaction_status', TransactionStatus::Submitted->value)
            ->whereDate('transaction_date', '<', today()->subDays(self::AGING_DAYS))
            ->orderBy('transaction_date')
            ->limit(10)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'name' => trim($transaction->lname.', '.$transaction->fname),
                'type' => $transaction->transaction_type->value,
                'days' => (int) $transaction->transaction_date->startOfDay()->diffInDays(today()),
                'date' => $transaction->transaction_date->toDateString(),
            ])->all();
    }
}
