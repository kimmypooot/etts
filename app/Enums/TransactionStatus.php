<?php

namespace App\Enums;

/**
 * The single source of truth for transaction status strings. The values are
 * the strings already stored in the legacy `transaction_status` column (the
 * production data holds only these ten), so imported records need no rewrite.
 *
 * Never write a raw status string in code or SQL: use a case, or one of the
 * grouped sets below with {@see self::values()}.
 */
enum TransactionStatus: string
{
    // Flow statuses, shared by every transaction type.
    case Submitted = 'submitted';
    case Forwarded = 'forwarded';
    case Received = 'received';

    // SPELS terminal statuses.
    case Approved = 'Approved';
    case ApprovedReleased = 'Approved/Released';
    case Disapproved = 'Disapproved';
    case Returned = 'Returned';

    // Certification / authentication terminal statuses.
    case Released = 'Released';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';

    /** @return list<self> */
    public static function flow(): array
    {
        return [self::Submitted, self::Forwarded, self::Received];
    }

    /** @return list<self> */
    public static function spelsTerminal(): array
    {
        return [self::Approved, self::ApprovedReleased, self::Disapproved, self::Returned];
    }

    /** @return list<self> */
    public static function certsTerminal(): array
    {
        return [self::Released, self::Completed, self::Cancelled];
    }

    /**
     * Terminal for either family. Production data has shown SPELS records
     * carrying Released or Cancelled, so a save must never move a record off
     * this list back to Received, whatever family its status nominally belongs to.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [...self::certsTerminal(), ...self::spelsTerminal()];
    }

    /**
     * The statuses that count toward the one-approval-per-eligibility rule.
     *
     * @return list<self>
     */
    public static function spelsApproved(): array
    {
        return [self::Approved, self::ApprovedReleased];
    }

    /**
     * The Active board. Completed is deliberately active, not archived: a
     * completed certification is processed but not yet released to the client,
     * so someone still has to finish it. This differs from {@see self::terminal()},
     * which counts Completed as terminal; do not use terminal() to split the boards.
     *
     * @return list<self>
     */
    public static function boardActive(): array
    {
        return [self::Submitted, self::Forwarded, self::Received, self::Completed];
    }

    /**
     * The Archive board: the exact complement of {@see self::boardActive()}.
     *
     * @return list<self>
     */
    public static function boardArchive(): array
    {
        return [self::Released, self::Approved, self::ApprovedReleased, self::Returned, self::Disapproved, self::Cancelled];
    }

    /**
     * The statuses a record of this type may legally hold or move to.
     *
     * @return list<self>
     */
    public static function allowedFor(TransactionType $type): array
    {
        return $type->isSpels()
            ? [...self::flow(), ...self::spelsTerminal()]
            : [...self::flow(), ...self::certsTerminal()];
    }

    /**
     * The stored strings for a set of cases, for a SQL IN list.
     *
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(fn (self $status) => $status->value, $statuses);
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isSpelsApproved(): bool
    {
        return in_array($this, self::spelsApproved(), true);
    }
}
