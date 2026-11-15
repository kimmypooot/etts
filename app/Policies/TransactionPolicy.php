<?php

namespace App\Policies;

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionWorkflow;

/**
 * The ESD transaction screens are for ESD administrators. A field office user
 * works through the field office portal, on their own office's records only
 * (the model's OfficeScope enforces that in SQL as well).
 */
class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->isAdmin() || ($user->office_id !== null && $user->office_id === $transaction->office_id);
    }

    /** Forward, receive, change status, batch and revert (ESD). */
    public function process(User $user, ?Transaction $transaction = null): bool
    {
        return $user->isAdmin();
    }

    // ─── Field office portal ──────────────────────────────────────────────
    // A field office user works only on their own office's records, and only
    // before the Regional Office has acted on them. These abilities are
    // separate from the ESD ones on purpose: the ESD routes stay
    // administrator-only whatever is added here.

    /** Whether the user may use the field office portal at all. */
    public function office(User $user): bool
    {
        return $user->role === UserRole::FieldOffice && $user->office_id !== null;
    }

    /** File a new transaction at the user's own office. */
    public function createAsOffice(User $user): bool
    {
        return $this->office($user);
    }

    /** View Details of an own-office record, whatever its status. */
    public function viewAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->office($user) && $transaction->office_id === $user->office_id;
    }

    /**
     * Edit, delete or forward an own-office record. Allowed only while it is
     * still with the office, that is while it is submitted. Once received,
     * forwarded or closed (returned included), the office only views it.
     */
    public function workAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->office($user)
            && $transaction->office_id === $user->office_id
            && self::stillWithOffice($transaction);
    }

    /**
     * Cancel an own-office certification or authentication. Narrower than
     * workAsOffice: only while it is still submitted (unpaid). Once received,
     * cancelling is ESD's call. SPELS has no Cancelled status; the office
     * deletes those instead.
     */
    public function cancelAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->office($user)
            && $transaction->office_id === $user->office_id
            && $transaction->transaction_type->isIssuance()
            && $transaction->transaction_status === TransactionStatus::Submitted;
    }

    /**
     * Receive a SPELS application on its form (take the payment, correct the
     * details) while it is still submitted. A certification or authentication
     * is received on the processing page instead ({@see receiveAsOffice()}).
     */
    public function editAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->workAsOffice($user, $transaction) && $transaction->transaction_type->isSpels();
    }

    /**
     * Receive a certification or authentication: the processing page, where
     * the office adds the attachments (and nothing else) while it is submitted.
     */
    public function receiveAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->workAsOffice($user, $transaction) && $transaction->transaction_type->isIssuance();
    }

    /**
     * Return (withdraw) an own-office SPELS application that is submitted and
     * not yet paid. SPELS has no Cancelled status; Returned is its equivalent.
     * A paid one is forwarded instead.
     */
    public function returnAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->workAsOffice($user, $transaction)
            && $transaction->transaction_type->isSpels()
            && ! TransactionWorkflow::isPaid($transaction);
    }

    public static function stillWithOffice(Transaction $transaction): bool
    {
        return $transaction->transaction_status === TransactionStatus::Submitted;
    }

    // ─── ESD ──────────────────────────────────────────────────────────────

    /** Create, edit and quick-edit a transaction. */
    public function manage(User $user, ?Transaction $transaction = null): bool
    {
        return $user->isAdmin();
    }

    /** Export the board to CSV: names and contact numbers leave the system. */
    public function export(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ?Transaction $transaction = null): bool
    {
        return $user->isAdmin();
    }
}
