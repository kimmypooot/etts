<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Office;
use App\Models\Transaction;
use App\Services\Reports\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ETTS's summary for the IMIS Executive Dashboard (feed version 1, see
 * new-imis docs/executive-feed.md). IMIS pulls it every 30 minutes with the key
 * it generated as a bearer token. Totals only: never a name, an ID number or a
 * record (RA 10173); breakdowns are by office, type or status.
 *
 * Off (404) until IMIS_FEED_KEY is set; 401 for a wrong key. It is read-only
 * and uses no session, so it has no user and sees every office.
 */
class ImisSummaryController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): JsonResponse
    {
        $key = (string) config('services.imis.feed_key');
        abort_if($key === '', 404);
        abort_unless(hash_equals($key, (string) $request->bearerToken()), 401);

        $offices = Office::pluck('code', 'id');
        $month = today()->format('F Y');
        $monthStart = today()->startOfMonth();

        $count = fn (array $statuses) => Transaction::whereIn('transaction_status', TransactionStatus::values($statuses))->count();

        $openByOffice = Transaction::query()->whereIn('transaction_status', TransactionStatus::values(TransactionStatus::boardActive()))
            ->selectRaw('office_id, count(*) as total')->groupBy('office_id')->orderByDesc('total')->get()
            ->map(fn ($row) => ['label' => $offices[$row->office_id] ?? "Office {$row->office_id}", 'value' => (int) $row->total])->all();

        $filedByType = collect(TransactionType::cases())->map(fn (TransactionType $type) => [
            'label' => $type->value,
            'value' => Transaction::where('transaction_type', $type->value)->whereBetween('transaction_date', [$monthStart, now()])->count(),
        ])->all();

        $turnaround = $metrics->turnaround();
        $decisions = Transaction::query()->whereIn('transaction_status', TransactionStatus::values(TransactionStatus::spelsTerminal()))
            ->whereBetween('date_completed', [$monthStart->toDateString(), today()->toDateString()])
            ->selectRaw('transaction_status, count(*) as total')->groupBy('transaction_status')->get()
            ->map(fn ($row) => ['label' => $row->transaction_status->value, 'value' => (int) $row->total])->all();

        return response()->json([
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'metrics' => [
                ['key' => 'etts.open', 'label' => 'Open transactions', 'value' => array_sum(array_column($openByOffice, 'value')), 'unit' => 'count', 'note' => 'Not yet finished', 'breakdown' => $openByOffice],
                ['key' => 'etts.filed_this_month', 'label' => 'Transactions filed this month', 'value' => array_sum(array_column($filedByType, 'value')), 'unit' => 'count', 'period' => $month, 'breakdown' => $filedByType],
                ['key' => 'etts.awaiting_receipt', 'label' => 'Applications awaiting receipt at ESD', 'value' => $count([TransactionStatus::Forwarded]), 'unit' => 'count', 'note' => 'Forwarded by field offices'],
                ['key' => 'etts.ready_to_release', 'label' => 'Documents ready to release', 'value' => $count([TransactionStatus::Completed, TransactionStatus::Approved]), 'unit' => 'count', 'note' => 'Completed or approved'],
                ['key' => 'etts.spels_decided_this_month', 'label' => 'Special eligibility decisions this month', 'value' => array_sum(array_column($decisions, 'value')), 'unit' => 'count', 'period' => $month, 'breakdown' => $decisions],
                ['key' => 'etts.days_to_complete_spels', 'label' => 'Average days to complete a special eligibility application', 'value' => $turnaround['spels']['days'], 'unit' => 'days', 'period' => 'Last 90 days', 'note' => $turnaround['spels']['count'].' completed'],
                ['key' => 'etts.days_to_complete_issuance', 'label' => 'Average days to complete a certification or authentication', 'value' => $turnaround['issuance']['days'], 'unit' => 'days', 'period' => 'Last 90 days', 'note' => $turnaround['issuance']['count'].' completed'],
                ['key' => 'etts.collected_this_month', 'label' => 'Fees collected this month', 'value' => (int) Transaction::whereBetween('transaction_date', [$monthStart, now()])->sum('amount'), 'unit' => 'peso', 'period' => $month],
            ],
        ]);
    }
}
