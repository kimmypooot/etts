<?php

namespace App\Policies;

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Transaction;
use App\Models\User;

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

    /** Forward, receive, change status, batch and revert. */
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

    /**
     * Edit, delete or forward an own-office record. Allowed while it is still
     * with the office: a SPELS application that is submitted or returned, or a
     * certification or authentication that has been received but not yet
     * completed.
     */
    public function workAsOffice(User $user, Transaction $transaction): bool
    {
        return $this->office($user)
            && $transaction->office_id === $user->office_id
            && self::stillWithOffice($transaction);
    }

    public static function stillWithOffice(Transaction $transaction): bool
    {
        return $transaction->transaction_type->isSpels()
            ? in_array($transaction->transaction_status, [TransactionStatus::Submitted, TransactionStatus::Returned], true)
            : $transaction->transaction_status === TransactionStatus::Received;
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
