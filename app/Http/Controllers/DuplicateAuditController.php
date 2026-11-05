<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Services\Spels\DuplicateAudit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The duplicate sweep: applicants who already hold more than one approved
 * SPELS record for the same eligibility. Administrators only, because it lists
 * whole records across every office (see {@see DuplicateAudit}).
 */
class DuplicateAuditController extends Controller
{
    public function __invoke(Request $request, DuplicateAudit $audit): Response
    {
        $date = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;

        $filters = [
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
            'eligibility' => trim((string) $request->query('eligibility', '')) ?: null,
        ];

        $report = $audit->sweep($filters['from'], $filters['to'], $filters['eligibility']);

        AuditLog::record('audit.duplicates_viewed', null, after: ['summary' => "Ran the SPELS duplicate sweep: {$report['groupCount']} group(s) found."]);

        return Inertia::render('Audit/Duplicates', [
            'filters' => $filters,
            'report' => $report,
            'eligibilities' => Eligibility::orderBy('code')->pluck('code')->all(),
        ]);
    }
}
