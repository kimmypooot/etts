<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Services\Transactions\LookupLists;
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
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'mobile' => ['required', 'string', 'max:12', 'regex:/^[0-9+\- ]*$/'],
            'email' => ['required', 'email', 'max:100'],

            'elig_code' => $spels ? ['required', 'string', 'max:255', LookupLists::spelsCodeRule()] : ['nullable'],

            'current_address' => [$issuance ? 'required' : 'nullable', 'string', 'max:500'],
            'civil_status' => [$issuance ? 'required' : 'nullable', Rule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'date_of_birth' => [$issuance ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'place_of_birth' => [$issuance ? 'required' : 'nullable', 'string', 'max:255'],
            'employment_status' => [$issuance ? 'required' : 'nullable', Rule::in(['Employed', 'Self-Employed', 'Unemployed'])],
            'company_name' => ['nullable', 'required_if:employment_status,Employed,Self-Employed', 'string', 'max:255'],
            'company_address' => ['nullable', 'required_if:employment_status,Employed,Self-Employed', 'string', 'max:500'],
            'purpose_of_request' => [$issuance ? 'required' : 'nullable', 'string', 'max:100', Rule::in([...config('etts.purposes'), 'Others'])],
            'purpose_others' => ['nullable', 'required_if:purpose_of_request,Others', 'string', 'max:80'],
            'used_lastname' => [$issuance ? 'required' : 'nullable', 'string', 'max:255'],
            'used_firstname' => [$issuance ? 'required' : 'nullable', 'string', 'max:255'],
            'used_middlename' => ['nullable', 'string', 'max:255'],
            'used_extn' => ['nullable', 'string', 'max:50'],
            'eligibility' => [$issuance ? 'required' : 'nullable', 'string', 'max:255', LookupLists::issuanceEligibilityRule()],
            'date_of_exam_issuance' => [$issuance ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'place_of_exam_issuance' => [$issuance ? 'required' : 'nullable', 'string', 'max:255', LookupLists::examCenterRule()],
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
            'eligibility.in' => 'Please choose your eligibility from the list.',
            'place_of_exam_issuance.in' => 'Please choose the place of examination from the list.',
            'sex.required' => 'Please choose your sex.',
            'mobile.required' => 'Please enter your mobile number.',
            'email.required' => 'Please enter your email address.',
            'civil_status.required' => 'Please choose your civil status.',
            'date_of_birth.required' => 'Please enter your date of birth.',
            'place_of_birth.required' => 'Please enter your place of birth.',
            'employment_status.required' => 'Please choose your employment.',
            'company_name.required_if' => 'Please enter your company or agency.',
            'company_address.required_if' => 'Please enter your company address.',
            'current_address.required' => 'Please enter your current address.',
            'eligibility.required' => 'Please choose your eligibility.',
            'date_of_exam_issuance.required' => 'Please enter the date of examination or issuance.',
            'place_of_exam_issuance.required' => 'Please choose the place of examination or issuance.',
            'used_lastname.required' => 'Please enter the last name you used.',
            'used_firstname.required' => 'Please enter the first name you used.',
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
