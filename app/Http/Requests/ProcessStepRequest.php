<?php

namespace App\Http\Requests;

use App\Services\Transactions\ProcessSteps;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One step of the issuance processing page. The route's `manage` gate does
 * the authorisation; the rules come from ProcessSteps.
 */
class ProcessStepRequest extends FormRequest
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
        $step = (int) $this->route('step');
        $rules = ProcessSteps::rules($step, $this->input('purpose_of_request') === 'Others') + ['version' => ['nullable', 'string', 'max:19']];

        if ($step === 4) {
            $maxKb = (int) config('etts.attachments.max_kb');
            $rules['attachment_1'] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb];
            $rules['attachment_2'] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'official_receipt.regex' => 'The OR number may only contain letters, digits, spaces, hyphens, slashes and #.',
            'mobile.regex' => 'The mobile number may only contain digits, spaces, + and -.',
            'transaction_type.in' => 'Choose Certification, Authentication, or both.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ((int) $this->route('step') === 4 && (int) $this->input('coe_copies') + (int) $this->input('auth_copies') < 1) {
                    $validator->errors()->add('coe_copies', 'Enter at least one copy of the certificate or the authentication.');
                }
            },
        ];
    }
}
