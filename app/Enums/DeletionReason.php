<?php

namespace App\Enums;

/**
 * Why a transaction was deleted. Every delete needs one; the codes are the
 * strings already stored as `deletion_reason_code` in the legacy archive.
 */
enum DeletionReason: string
{
    case Duplicate = 'DUPLICATE';
    case EncodingError = 'ENCODING_ERROR';
    case ClientWithdrew = 'CLIENT_WITHDREW';
    case TestData = 'TEST_DATA';
    case Other = 'OTHER';

    /** Length bounds for the free-text note that accompanies a deletion. */
    public const NOTE_MIN = 5;

    public const NOTE_MAX = 1000;

    public function label(): string
    {
        return match ($this) {
            self::Duplicate => 'Duplicate entry',
            self::EncodingError => 'Encoding / data entry error',
            self::ClientWithdrew => 'Client withdrew the request',
            self::TestData => 'Test or invalid data',
            self::Other => 'Other',
        };
    }
}
