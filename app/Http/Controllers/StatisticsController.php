<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Services\Reports\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The statistics reports (certifications and authentications; special
 * eligibility applications) with their drill-down and CSV export. The counting
 * is in {@see StatisticsService}.
 */
class StatisticsController extends Controller
{
    public function __construct(private StatisticsService $statistics) {}

    public function index(Request $request): Response
    {
        [$category, $from, $to, $office] = $this->input($request);

        return Inertia::render('Statistics/Index', [
            'category' => $category,
            'filters' => ['from' => substr($from, 0, 10), 'to' => substr($to, 0, 10), 'office' => $office],
            'report' => $this->statistics->report($category, $from, $to, $office),
            'offices' => Office::orderBy('name')->get(['id', 'code'])->map(fn (Office $o) => ['value' => $o->id, 'label' => $o->code])->all(),
        ]);
    }

    /**
     * The records behind one cell (or one column) of the report.
     */
    public function details(Request $request): JsonResponse
    {
        [$category, $from, $to, $office] = $this->input($request);
        $metric = (string) $request->query('metric');
        $group = $request->query('group');

        $page = $this->statistics->details($category, $metric, is_string($group) ? $group : null, $from, $to, $office);

        return response()->json([
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'data' => $page->getCollection()->map(fn (Transaction $t) => [
                'id' => $t->id,
                'name' => trim($t->lname.', '.$t->fname),
                'type' => $t->transaction_type->value,
                'eligibility' => $t->elig_code,
                'office' => $t->office?->code,
                'status' => $t->transaction_status->value,
                'filed' => $t->transaction_date->toDateString(),
                'sent' => $t->date_sent_to_ro?->toDateString(),
                'received' => $t->date_received_ro?->toDateString(),
                'completed' => $t->date_completed?->toDateString(),
                'officer' => $t->action_officer,
            ])->all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$category, $from, $to, $office] = $this->input($request);
        $report = $this->statistics->report($category, $from, $to, $office);

        AuditLog::record('statistics.exported', null, after: ['summary' => "Exported the {$category} statistics ".substr($from, 0, 10).' to '.substr($to, 0, 10).'.']);

        return response()->streamDownload(function () use ($report, $category) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$category === StatisticsService::COE_AUTH ? 'Type' : 'Eligibility', ...array_values($report['columns'])]);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [$this->safe($row['group']), ...array_map(fn (string $key) => $row[$key], array_keys($report['columns']))]);
            }

            fputcsv($out, ['Total', ...array_map(fn (string $key) => $report['totals'][$key], array_keys($report['columns']))]);
            fclose($out);
        }, "statistics-{$category}-".substr($from, 0, 10).'-to-'.substr($to, 0, 10).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The report's category, date range (whole days) and office, from the query.
     *
     * @return array{string, string, string, ?int}
     */
    private function input(Request $request): array
    {
        $date = fn (mixed $value, string $default) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $default;

        $from = $date($request->query('from'), today()->startOfYear()->toDateString());
        $to = $date($request->query('to'), today()->toDateString());

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [StatisticsService::category($request->query('category')), $from.' 00:00:00', $to.' 23:59:59', $request->integer('office') ?: null];
    }

    private function safe(string $value): string
    {
        return in_array($value[0] ?? '', ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
