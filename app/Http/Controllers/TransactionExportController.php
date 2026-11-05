<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Transactions\BoardFilters;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the board you are looking at (same filters, same order) as CSV.
 * Applicant names and contact numbers leave the system here, so the export is
 * restricted by policy and audited. Cells that a spreadsheet would run as a
 * formula are defused.
 */
class TransactionExportController extends Controller
{
    private const HEADERS = ['ID', 'Last name', 'First name', 'Middle name', 'Ext.', 'Type', 'Office', 'Eligibility', 'Filed', 'Status', 'OR number', 'OR date', 'Amount', 'Sent to RO', 'Received by AO', 'Completed', 'Action officer', 'Details', 'Remarks'];

    public function __invoke(Request $request): StreamedResponse
    {
        $filters = BoardFilters::fromRequest($request);
        $query = BoardFilters::sorted(BoardFilters::query($filters)->with('office:id,code'), $filters);

        AuditLog::record('transactions.exported', null, after: ['summary' => "Exported the {$filters['board']} board to CSV.", 'filters' => $filters]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the UTF-8 names correctly
            fputcsv($out, self::HEADERS);

            $query->chunk(500, function ($rows) use ($out) {
                /** @var Transaction $row */
                foreach ($rows as $row) {
                    fputcsv($out, array_map($this->safe(...), [
                        $row->id, $row->lname, $row->fname, $row->mname, $row->extn, $row->transaction_type->value,
                        $row->office?->code, $row->elig_code, $row->transaction_date->toDateString(), $row->transaction_status->value,
                        $row->official_receipt, $row->or_date?->toDateString(), $row->amount,
                        $row->date_sent_to_ro?->toDateString(), $row->date_received_ro?->toDateString(), $row->date_completed?->toDateString(),
                        $row->action_officer, $row->transaction_details, $row->remarks,
                    ]));
                }
            });

            fclose($out);
        }, 'transactions-'.$filters['board'].'-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A cell starting with = + - @ (or a tab or return) would be run as a
     * formula by Excel; a leading apostrophe makes it plain text.
     */
    private function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
