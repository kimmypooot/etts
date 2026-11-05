<?php

namespace App\Http\Controllers\Fo;

use App\Http\Controllers\TransactionTimelineController;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The history of one of the office's own records, live or deleted. A record of
 * another office is reported as not found, as if it did not exist.
 */
class TimelineController extends TransactionTimelineController
{
    protected function guard(int $id): void
    {
        $officeId = Auth::user()->office_id;

        $own = Transaction::query()->whereKey($id)->exists()
            || DB::table('deleted_transactions')->where('id', $id)->where('office_id', $officeId)->exists();

        abort_unless($own, 404);
    }
}
