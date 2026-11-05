<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Transactions\AttachmentStore;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows an applicant attachment. The files sit on a private disk and cannot be
 * reached by URL; this is the only way in. The route authorises the request,
 * the file name comes from the record (never from the request), and every view
 * is audited, because these are identity documents (RA 10173).
 */
class AttachmentController extends Controller
{
    public function __invoke(int $id, int $slot, AttachmentStore $attachments): Response
    {
        abort_unless(in_array($slot, [1, 2], true), 404);

        $column = "attachment_{$slot}";

        // A live record first; a deleted one keeps its issuance request in the archive.
        $name = DB::table('issuance_requests')->where('transaction_id', $id)->value($column)
            ?? DB::table('deleted_issuance_requests')->where('transaction_id', $id)->value($column);

        abort_if($name === null || $name !== basename((string) $name) || ! $attachments->exists($name), 404, 'This attachment is not available.');

        AuditLog::record('attachment.viewed', (new Transaction)->forceFill(['id' => $id]),
            after: ['summary' => "Viewed attachment {$slot}.", 'slot' => $slot, 'file' => $name],
        );

        return $attachments->response($name);
    }
}
