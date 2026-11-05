<?php

namespace App\Http\Requests;

use App\Models\Eligibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Codes are upper case and may hold letters, digits, spaces, dashes,
     * underscores and brackets, because that is what the production data has
     * (for example "CSE-PR (JOCOSC6)" and "MC 11"). The legacy form refused
     * those and so could not re-save its own rows.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Eligibility|null $eligibility */
        $eligibility = $this->route('eligibility');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9][A-Z0-9 ()_\-]*$/',
                Rule::unique('eligibilities', 'code')->ignore($eligibility?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use up to 20 letters, numbers, spaces, dashes, underscores or brackets.',
            'code.unique' => 'This code is already used by another eligibility.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(preg_replace('/\s+/', ' ', trim((string) $this->input('code')))),
            'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
        ]);
    }
}
