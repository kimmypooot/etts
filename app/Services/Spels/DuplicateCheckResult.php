<?php

namespace App\Services\Spels;

/**
 * The outcome of the SPELS duplicate-eligibility check.
 *
 * Deliberately narrow: each matched record carries only its id, status, dates
 * and office id, never contact details, and the applicant name is the one the
 * caller supplied. This result reaches the unauthenticated kiosk, so any field
 * added here is shown to an anonymous caller.
 */
final readonly class DuplicateCheckResult
{
    /**
     * @param  list<array{id: int, transaction_status: string, transaction_date: ?string, date_completed: ?string, office_id: int}>  $records
     */
    public function __construct(
        public bool $blocked,
        public int $approvedCount,
        public int $cap,
        public array $records,
        public string $eligCode,
        public string $lname,
        public string $fname,
    ) {}

    public static function none(string $eligCode = '', string $lname = '', string $fname = ''): self
    {
        return new self(false, 0, DuplicateCheck::APPROVED_CAP, [], $eligCode, $lname, $fname);
    }
}
