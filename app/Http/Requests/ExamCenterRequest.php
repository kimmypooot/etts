<?php

namespace App\Http\Requests;

use App\Models\ExamCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ExamCenter|null $examCenter */
        $examCenter = $this->route('examCenter');

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('exam_centers', 'name')->ignore($examCenter?->id)],
            'country' => ['required', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => 'This place is already on the list.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
            'country' => preg_replace('/\s+/', ' ', trim((string) $this->input('country', 'Philippines'))) ?: 'Philippines',
        ]);
    }
}
