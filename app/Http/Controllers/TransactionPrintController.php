<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Transactions\AttachmentStore;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * The two documents ESD prints for a certification or authentication request,
 * laid out as the legacy system printed them: the Eligibility/Exam Records
 * Request Form (ERRF) and the authenticated copy (the applicant's own document
 * with the "Certified Authenticated Copy" stamp and a QR code of the record).
 *
 * Each opens in its own tab and offers the browser's print dialog. Printing is
 * audited because both carry personal data.
 */
class TransactionPrintController extends Controller
{
    public function errf(Transaction $transaction): View
    {
        abort_unless($transaction->transaction_type->isIssuance(), 404);

        $issuance = $transaction->issuanceRequest;
        $details = (string) $transaction->transaction_details;

        AuditLog::record('transaction.printed', $transaction, after: ['summary' => 'Printed the request form (ERRF).']);

        return view('prints.errf', [
            't' => $transaction,
            'r' => $issuance,
            'isCert' => str_contains(strtolower($transaction->transaction_type->value), 'certification'),
            'isAuth' => str_contains(strtolower($transaction->transaction_type->value), 'authentication'),
            // The fee line reads like "COE-1, AUTH-2".
            'coeCopies' => preg_match('/COE-(\d+)/i', $details, $m) ? $m[1] : '',
            'authCopies' => preg_match('/AUTH-(\d+)/i', $details, $m) ? $m[1] : '',
        ]);
    }

    public function authenticatedCopy(Request $request, Transaction $transaction, AttachmentStore $attachments): View
    {
        abort_unless($transaction->transaction_type->value !== 'Special Eligibility Application' && str_contains($transaction->transaction_type->value, 'Authentication'), 404);

        $data = $request->validate([
            'signatory' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'office' => ['nullable', 'string', 'max:160'],
        ], ['signatory.required' => 'Choose who signs the authenticated copy.']);

        $name = $transaction->issuanceRequest?->attachment_2;
        abort_if($name === null || $name !== basename($name) || ! $attachments->exists($name), 404, 'There is no original document attached to authenticate (Attachment 2).');

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        AuditLog::record('transaction.printed', $transaction, after: ['summary' => 'Printed the authenticated copy.', 'signatory' => $data['signatory']]);

        return view('prints.authenticated-copy', [
            't' => $transaction,
            'isImage' => in_array($extension, ['jpg', 'jpeg', 'png'], true),
            'imageUrl' => route('attachments.show', ['id' => $transaction->id, 'slot' => 2]),
            'signatory' => $data['signatory'],
            'position' => $data['position'] ?? '',
            'office' => $data['office'] ?? '',
            'orDate' => $transaction->or_date?->format('F j, Y') ?? 'N/A',
            'orNumber' => $transaction->official_receipt ?: 'N/A',
        ]);
    }
}
