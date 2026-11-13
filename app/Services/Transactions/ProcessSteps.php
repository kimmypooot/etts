<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Validation\Rule;

/**
 * The rules of the issuance processing page (Certification, Authentication,
 * or both): which fields each step holds, which of them are required, where
 * a record stands, and whether it can still be edited. One place, so the
 * controller, the request and the page cannot disagree.
 *
 * Values are the `record` array of TransactionFormPresenter::edit().
 */
final class ProcessSteps
{
    private const FIELDS = [
        1 => ['lname', 'fname', 'mname', 'extn', 'date_of_birth', 'sex', 'place_of_birth', 'civil_status', 'current_address', 'mobile', 'email', 'purpose_of_request', 'purpose_others', 'employment_status', 'company_name', 'company_address'],
        2 => ['eligibility', 'date_of_exam_issuance', 'place_of_exam_issuance', 'used_lastname', 'used_firstname', 'used_middlename', 'used_extn'],
        3 => ['book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating', 'release_date', 'effectivity_date'],
        4 => ['transaction_type', 'coe_copies', 'auth_copies', 'official_receipt', 'or_date'],
    ];

    private const REQUIRED = [
        1 => ['lname', 'fname', 'purpose_of_request'],
        2 => ['eligibility', 'date_of_exam_issuance', 'place_of_exam_issuance'],
        3 => ['book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating', 'release_date'],
        4 => ['transaction_type', 'official_receipt'],
    ];

    /** Field formats, the same as TransactionFormRequest's. */
    private const FORMATS = [
        'lname' => ['string', 'max:255'],
        'fname' => ['string', 'max:255'],
        'mname' => ['string', 'max:255'],
        'extn' => ['string', 'max:10'],
        'date_of_birth' => ['date_format:Y-m-d'],
        'sex' => ['in:Male,Female'],
        'place_of_birth' => ['string', 'max:255'],
        'civil_status' => ['in:Single,Married,Widowed,Separated,Annulled'],
        'current_address' => ['string', 'max:500'],
        'mobile' => ['string', 'max:12', 'regex:/^[0-9+\- ]*$/'],
        'email' => ['email', 'max:100'],
        'purpose_of_request' => ['string', 'max:100'],
        'purpose_others' => ['string', 'max:80'],
        'employment_status' => ['in:Employed,Unemployed,Self-Employed,Student,Retired'],
        'company_name' => ['string', 'max:255'],
        'company_address' => ['string', 'max:500'],
        'eligibility' => ['string', 'max:255'],
        'date_of_exam_issuance' => ['date_format:Y-m-d'],
        'place_of_exam_issuance' => ['string', 'max:255'],
        'used_lastname' => ['string', 'max:255'],
        'used_firstname' => ['string', 'max:255'],
        'used_middlename' => ['string', 'max:255'],
        'used_extn' => ['string', 'max:50'],
        'book' => ['string', 'max:25'],
        'page' => ['string', 'max:5'],
        'sequence' => ['string', 'max:5'],
        'examinee_no' => ['string', 'max:50'],
        'cert_no' => ['string', 'max:15'],
        'rating' => ['string', 'max:12'],
        'release_date' => ['date_format:Y-m-d'],
        'effectivity_date' => ['date_format:Y-m-d'],
        'official_receipt' => ['string', 'max:50', 'regex:/^[\w\-\/# ]*$/u'],
        'or_date' => ['date_format:Y-m-d'],
    ];

    /** @return list<string> */
    public static function fields(int $step): array
    {
        return self::FIELDS[$step] ?? [];
    }

    /**
     * Validation rules for one step's fields.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(int $step, bool $othersPurpose = false): array
    {
        $required = self::REQUIRED[$step] ?? [];

        if ($othersPurpose && $step === 1) {
            $required[] = 'purpose_others';
        }

        $rules = [];

        foreach (self::fields($step) as $field) {
            $rules[$field] = [in_array($field, $required, true) ? 'required' : 'nullable', ...(self::FORMATS[$field] ?? [])];
        }

        if ($step === 4) {
            $rules['transaction_type'] = ['required', Rule::in(self::issuanceTypes())];
            $rules['coe_copies'] = ['required', 'integer', 'min:0', 'max:99'];
            $rules['auth_copies'] = ['required', 'integer', 'min:0', 'max:99'];
        }

        return $rules;
    }

    /**
     * The required fields of a step that are still blank.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missing(int $step, array $values): array
    {
        $required = self::REQUIRED[$step] ?? [];

        if ($step === 1 && ($values['purpose_of_request'] ?? null) === 'Others') {
            $required[] = 'purpose_others';
        }

        $missing = array_values(array_filter($required, fn (string $field) => blank($values[$field] ?? null)));

        if ($step === 4 && (int) ($values['coe_copies'] ?? 0) + (int) ($values['auth_copies'] ?? 0) < 1) {
            $missing[] = 'coe_copies';
        }

        return $missing;
    }

    /**
     * The first step with a required field still blank; 5 once 1–4 are done.
     *
     * @param  array<string, mixed>  $values
     */
    public static function firstIncompleteStep(array $values): int
    {
        foreach ([1, 2, 3, 4] as $step) {
            if (self::missing($step, $values) !== []) {
                return $step;
            }
        }

        return 5;
    }

    /** A completed, released or cancelled record is no longer edited. */
    public static function isLocked(Transaction $transaction): bool
    {
        return in_array($transaction->transaction_status, [TransactionStatus::Completed, TransactionStatus::Released, TransactionStatus::Cancelled], true);
    }

    /** The status move step 5 offers: 'complete', 'release' or none. */
    public static function finishAction(Transaction $transaction): ?string
    {
        return match ($transaction->transaction_status) {
            TransactionStatus::Received => 'complete',
            TransactionStatus::Completed => 'release',
            default => null,
        };
    }

    /** @return list<string> */
    private static function issuanceTypes(): array
    {
        return array_values(array_map(
            fn (TransactionType $type) => $type->value,
            array_filter(TransactionType::cases(), fn (TransactionType $type) => $type->isIssuance()),
        ));
    }
}
