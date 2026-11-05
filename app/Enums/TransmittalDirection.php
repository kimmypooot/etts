<?php

namespace App\Enums;

/**
 * Which way a transmittal batch travels. The values are the strings in the
 * legacy `direction` column.
 */
enum TransmittalDirection: string
{
    /** ESD hands finished documents down to a field office. */
    case EsdToFo = 'ESD_TO_FO';

    /** A field office sends SPELS applications up to the Regional Office. */
    case FoToRo = 'FO_TO_RO';

    public function label(): string
    {
        return match ($this) {
            self::EsdToFo => 'To field office',
            self::FoToRo => 'From field office',
        };
    }

    /** The prefix of this direction's batch numbers. */
    public function prefix(): string
    {
        return match ($this) {
            self::EsdToFo => 'TRN',
            self::FoToRo => 'FTR',
        };
    }

    /**
     * The status a batch ends in once it has been handed over: ESD releases its
     * batches, a field office transmits its own.
     */
    public function finalStatus(): TransmittalStatus
    {
        return match ($this) {
            self::EsdToFo => TransmittalStatus::Released,
            self::FoToRo => TransmittalStatus::Transmitted,
        };
    }

    /**
     * Statuses that make a transaction eligible for a batch in this direction.
     * The two directions agree on almost nothing: finished work goes down,
     * new or corrected applications go up.
     *
     * @return list<TransactionStatus>
     */
    public function eligibleStatuses(): array
    {
        return match ($this) {
            self::EsdToFo => [TransactionStatus::Completed, TransactionStatus::Approved, TransactionStatus::Disapproved, TransactionStatus::Returned],
            self::FoToRo => [TransactionStatus::Submitted, TransactionStatus::Returned],
        };
    }
}
