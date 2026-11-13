<?php

namespace App\Services\Transactions;

/**
 * The `vcas://` link that hands a certification to the VCAS desktop launcher
 * on an ESD workstation: 29 values in the legacy order, joined by "/".
 *
 * Built from the saved record only. Each value is encoded on its own
 * (rawurlencode), so a slash or space inside a value (BLK 1/LOT 2) can no
 * longer shift the fields after it, which the legacy encodeURI allowed.
 * An empty value is sent as "NA", as before.
 */
final class VcasLink
{
    private const FIELDS = [
        'transaction_type', 'lname', 'fname', 'mname', 'extn',
        'date_of_birth', 'place_of_birth', 'sex', 'civil_status', 'current_address',
        'employment_status', 'company_name', 'company_address', 'purpose_of_request',
        'used_lastname', 'used_firstname', 'used_middlename', 'used_extn',
        'eligibility', 'date_of_exam_issuance', 'place_of_exam_issuance',
        'book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating',
        'release_date', 'effectivity_date',
    ];

    /**
     * @param  array<string, mixed>  $values  The record array of TransactionFormPresenter::edit().
     */
    public static function for(array $values): string
    {
        if (($values['purpose_of_request'] ?? null) === 'Others') {
            $values['purpose_of_request'] = 'Others: '.($values['purpose_others'] ?? '');
        }

        $encoded = array_map(function (string $field) use ($values) {
            $value = trim((string) preg_replace('/\s*\R\s*/u', ' ', (string) ($values[$field] ?? '')));

            return $value === '' ? 'NA' : rawurlencode($value);
        }, self::FIELDS);

        return 'vcas://'.implode('/', $encoded);
    }
}
