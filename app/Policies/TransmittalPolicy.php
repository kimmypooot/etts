<?php

namespace App\Policies;

use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use App\Enums\UserRole;
use App\Models\Transmittal;
use App\Models\User;

/**
 * ESD administrators handle every transmittal. A field office user works only
 * with their own office's batches: the ones they send up to the Regional Office,
 * and the released ones ESD sends down to them. The model's office scope
 * enforces the same in SQL.
 */
class TransmittalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Transmittal $transmittal): bool
    {
        return $user->isAdmin() || ($user->office_id !== null && $user->office_id === $transmittal->fieldOfficeId());
    }

    /** Create, edit, release and delete a draft at ESD. */
    public function manage(User $user, ?Transmittal $transmittal = null): bool
    {
        return $user->isAdmin();
    }

    // ─── Field office portal ──────────────────────────────────────────────

    /** Whether the user may use the field office portal at all. */
    public function office(User $user): bool
    {
        return $user->role === UserRole::FieldOffice && $user->office_id !== null;
    }

    /**
     * Open a batch of the user's own office. A batch ESD is still preparing
     * (a draft going down) is ESD's business and stays hidden.
     */
    public function viewAsOffice(User $user, Transmittal $transmittal): bool
    {
        if (! $this->office($user) || $user->office_id !== $transmittal->fieldOfficeId()) {
            return false;
        }

        return $transmittal->direction === TransmittalDirection::FoToRo || $transmittal->status !== TransmittalStatus::Draft;
    }

    /** Build, edit, transmit or delete a batch the user's office is sending up. */
    public function workAsOffice(User $user, Transmittal $transmittal): bool
    {
        return $this->office($user)
            && $transmittal->direction === TransmittalDirection::FoToRo
            && $transmittal->origin_office_id === $user->office_id;
    }
}
