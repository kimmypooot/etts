<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * A cheap "has anything changed?" signature for the boards' live refresh
 * (replaces the legacy `check_updates` poll). The model's OfficeScope narrows
 * it, so a field office only ever sees a signature of its own records and
 * learns nothing about other offices. The count catches deletions, which
 * `updated_at` alone would miss.
 */
class TransactionPulseController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $row = Transaction::query()->selectRaw('count(*) as total, max(updated_at) as latest')->first();

        return response()->json(
            ['signature' => $row->total.'|'.$row->latest],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
