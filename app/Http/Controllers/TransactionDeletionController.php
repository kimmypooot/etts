<?php

namespace App\Http\Controllers;

use App\Enums\DeletionReason;
use App\Services\Transactions\TransactionArchiver;
use App\Services\Transactions\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Deletes transactions into the archive. A reason and a note are mandatory.
 */
class TransactionDeletionController extends Controller
{
    public function __invoke(Request $request, TransactionArchiver $archiver): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'reason' => ['required', Rule::enum(DeletionReason::class)],
            'note' => ['required', 'string', 'min:'.DeletionReason::NOTE_MIN, 'max:'.DeletionReason::NOTE_MAX],
        ]);

        try {
            $count = $archiver->archive($data['ids'], DeletionReason::from($data['reason']), $data['note'], $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $count === 1 ? 'Transaction archived and removed.' : "{$count} transactions archived and removed.");
    }
}
