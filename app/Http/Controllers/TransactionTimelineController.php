<?php

namespace App\Http\Controllers;

use App\Enums\DeletionReason;
use App\Models\AuditLog;
use App\Models\DeletedTransaction;
use App\Models\LegacyActivityLog;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * The history of one record, live or archived: the imported legacy activity
 * trail and the audit entries this app writes, newest first. A deleted record
 * also carries its deletion banner (who, when, why).
 */
class TransactionTimelineController extends Controller
{
    private const LIMIT = 500;

    /**
     * Stops a request for a record the caller may not see. ESD (administrators
     * only, by its route) sees every record; the field office portal narrows it.
     */
    protected function guard(int $id): void {}

    public function __invoke(int $id): JsonResponse
    {
        $this->guard($id);

        $deleted = DeletedTransaction::find($id);

        $legacy = LegacyActivityLog::query()
            ->where('record_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (LegacyActivityLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'who' => $log->username,
                'action' => $log->action_type,
                'details' => $log->details,
                'source' => 'legacy',
            ]);

        $current = AuditLog::query()
            ->with('user:id,name,username')
            ->where('subject_type', (new Transaction)->getMorphClass())
            ->where('subject_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'who' => $log->user?->username ?? 'system',
                'action' => $log->action,
                'details' => $log->after['summary'] ?? null,
                'source' => 'current',
            ]);

        return response()->json([
            'id' => $id,
            'deleted' => $deleted ? [
                'at' => $deleted->deleted_at?->toIso8601String(),
                'by' => $deleted->deleted_by_name,
                'reason' => $deleted->reason()?->label() ?? $deleted->deletion_reason_code,
                'note' => $deleted->deletion_reason_note,
            ] : null,
            'entries' => $legacy->concat($current)->sortByDesc('at')->values()->take(self::LIMIT)->all(),
        ]);
    }
}
