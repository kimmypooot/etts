<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a member of the public may submit at a kiosk. Narrower than the staff
 * form: no office (the terminal decides), no status, no payment, no remarks.
 * Wording is for a person at a counter, not for staff.
 */
class KioskRequestRequest extends FormRequest
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
        $issuance = $type?->isIssuance() ?? false;
        $maxKb = config('etts.attachments.max_kb');

        return [
            'transaction_type' => ['required', Rule::enum(TransactionType::class)],
            'privacy_acknowledged' => ['accepted'],
            'website' => ['nullable', 'max:0'], // a field people cannot see: only a script fills it

            'lname' => ['required', 'string', 'max:255'],
            'fname' => ['required', 'string', 'max:255'],
            'mname' => ['nullable', 'string', 'max:255'],
            'extn' => ['nullable', 'string', 'max:10'],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'mobile' => ['nullable', 'string', 'max:12', 'regex:/^[0-9+\- ]*$/'],
            'email' => ['nullable', 'email', 'max:100'],

            'elig_code' => $spels ? ['required', 'string', 'max:255', Rule::exists('eligibilities', 'code')] : ['nullable'],

            'current_address' => [$issuance ? 'required' : 'nullable', 'string', 'max:500'],
            'civil_status' => ['nullable', Rule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', Rule::in(['Employed', 'Self-Employed', 'Unemployed'])],
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'purpose_of_request' => [$issuance ? 'required' : 'nullable', 'string', 'max:100', Rule::in([...config('etts.purposes'), 'Others'])],
            'purpose_others' => ['nullable', 'required_if:purpose_of_request,Others', 'string', 'max:80'],
            'used_lastname' => ['nullable', 'string', 'max:255'],
            'used_firstname' => ['nullable', 'string', 'max:255'],
            'used_middlename' => ['nullable', 'string', 'max:255'],
            'used_extn' => ['nullable', 'string', 'max:50'],
            'eligibility' => ['nullable', 'string', 'max:255'],
            'date_of_exam_issuance' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'place_of_exam_issuance' => ['nullable', 'string', 'max:255'],
            'attachment_1' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb],
            'attachment_2' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb],
        ];
    }

    /**
     * Staff accept most of the types here; the kiosk takes all four.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'privacy_acknowledged.accepted' => 'Please read and accept the Data Privacy Notice to continue.',
            'lname.required' => 'Please enter your last name.',
            'fname.required' => 'Please enter your first name.',
            'elig_code.required' => 'Please choose the eligibility you are applying for.',
            'elig_code.exists' => 'Please choose an eligibility from the list.',
            'current_address.required' => 'Please enter your current address.',
            'purpose_of_request.required' => 'Please tell us what the request is for.',
            'purpose_others.required_if' => 'Please tell us what the request is for.',
            'mobile.regex' => 'Your mobile number may only contain digits, spaces, + and -.',
            'email.email' => 'Please check your email address.',
            'date_of_birth.before_or_equal' => 'Your date of birth cannot be in the future.',
            'date_of_exam_issuance.before_or_equal' => 'The date cannot be in the future.',
            'attachment_1.max' => 'A file may not be larger than '.round(config('etts.attachments.max_kb') / 1024).' MB.',
            'attachment_2.max' => 'A file may not be larger than '.round(config('etts.attachments.max_kb') / 1024).' MB.',
            'attachment_1.mimes' => 'Please attach a PDF, JPG or PNG file.',
            'attachment_2.mimes' => 'Please attach a PDF, JPG or PNG file.',
        ];
    }

    /**
     * A filled honeypot is a script: refuse without saying why.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (filled($this->input('website'))) {
                    $validator->errors()->add('form', 'Submission failed. Please see the Action Officer for assistance.');
                }
            },
        ];
    }
}
