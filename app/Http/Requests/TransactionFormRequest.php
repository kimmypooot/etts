<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit share one form. Certification and authentication requests
 * carry the extra issuance details; SPELS applications carry an eligibility
 * and a free amount instead.
 */
class TransactionFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = TransactionType::tryFrom((string) $this->input('transaction_type'));
        $spels = $type?->isSpels() ?? false;
        $maxKb = config('etts.attachments.max_kb');

        return [
            'transaction_type' => ['required', Rule::enum(TransactionType::class)],
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'lname' => ['required', 'string', 'max:255'],
            'fname' => ['required', 'string', 'max:255'],
            'mname' => ['nullable', 'string', 'max:255'],
            'extn' => ['nullable', 'string', 'max:10'],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'mobile' => ['nullable', 'string', 'max:12', 'regex:/^[0-9+\- ]*$/'],
            'email' => ['nullable', 'email', 'max:100'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'elig_code' => $spels ? ['required', 'string', 'max:255', Rule::exists('eligibilities', 'code')] : ['nullable', 'string', 'max:255'],
            'official_receipt' => ['nullable', 'string', 'max:50', 'regex:/^[\w\-\/# ]*$/u'],
            'or_date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'transaction_details' => ['nullable', 'string', 'max:500'],
            'version' => ['nullable', 'string', 'max:19'],

            'coe_copies' => ['nullable', 'integer', 'min:0', 'max:99'],
            'auth_copies' => ['nullable', 'integer', 'min:0', 'max:99'],
            'current_address' => ['nullable', 'string', 'max:500'],
            'civil_status' => ['nullable', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Annulled'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'purpose_of_request' => ['nullable', 'string', 'max:100'],
            'purpose_others' => ['nullable', 'string', 'max:80'],
            'employment_status' => ['nullable', Rule::in(['Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'])],
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'used_lastname' => ['nullable', 'string', 'max:255'],
            'used_firstname' => ['nullable', 'string', 'max:255'],
            'used_middlename' => ['nullable', 'string', 'max:255'],
            'used_extn' => ['nullable', 'string', 'max:50'],
            'used_address' => ['nullable', 'string', 'max:500'],
            'eligibility' => ['nullable', 'string', 'max:255'],
            'date_of_exam_issuance' => ['nullable', 'date_format:Y-m-d'],
            'place_of_exam_issuance' => ['nullable', 'string', 'max:255'],
            'book' => ['nullable', 'string', 'max:25'],
            'page' => ['nullable', 'string', 'max:5'],
            'sequence' => ['nullable', 'string', 'max:5'],
            'examinee_no' => ['nullable', 'string', 'max:50'],
            'cert_no' => ['nullable', 'string', 'max:15'],
            'rating' => ['nullable', 'string', 'max:12'],
            'effectivity_date' => ['nullable', 'date_format:Y-m-d'],
            'release_date' => ['nullable', 'date_format:Y-m-d'],
            'attachment_1' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb],
            'attachment_2' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb],
        ];
    }

    /**
     * A new certification or authentication needs at least one copy to price.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = TransactionType::tryFrom((string) $this->input('transaction_type'));

                if ($this->isMethod('POST') && $type?->isIssuance() && (int) $this->input('coe_copies') + (int) $this->input('auth_copies') < 1) {
                    $validator->errors()->add('coe_copies', 'Enter at least one copy of the certificate or the authentication.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'elig_code.exists' => 'Choose an eligibility from the list.',
            'official_receipt.regex' => 'The OR number may only contain letters, digits, spaces, hyphens, slashes and #.',
            'mobile.regex' => 'The mobile number may only contain digits, spaces, + and -.',
        ];
    }
}
