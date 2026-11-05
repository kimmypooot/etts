<?php

namespace App\Enums;

/**
 * What a client is asking ESD for. The values are the strings already stored in
 * the legacy `transaction_type` column, so imported records need no rewrite.
 */
enum TransactionType: string
{
    case Certification = 'Certification';
    case Authentication = 'Authentication';
    case CertificationAndAuthentication = 'Certification and Authentication';
    case Spels = 'Special Eligibility Application';

    /** Certification and authentication types go through the issuance flow (they have an issuance request). */
    public function isIssuance(): bool
    {
        return $this !== self::Spels;
    }

    public function isSpels(): bool
    {
        return $this === self::Spels;
    }

    /**
     * @return list<self>
     */
    public static function issuance(): array
    {
        return [self::Certification, self::Authentication, self::CertificationAndAuthentication];
    }

    public function label(): string
    {
        return match ($this) {
            self::Spels => 'Special Eligibility Application (SPELS)',
            default => $this->value,
        };
    }
}
