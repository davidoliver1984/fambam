<?php

namespace App\Http\Requests;

use App\Enums\DuplicateResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDuplicateHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(DuplicateResolution::class)],
            'existing_photo_id' => [
                'nullable',
                'string',
                'size:26',
                Rule::requiredIf($this->input('resolution') === DuplicateResolution::UseExisting->value),
            ],
            'disclosed_photo_ids' => [
                'nullable',
                'array',
                'min:1',
                'max:100',
                Rule::requiredIf($this->input('resolution') === DuplicateResolution::CreateNew->value),
            ],
            'disclosed_photo_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'confirm_visibility_widening' => ['sometimes', 'boolean'],
        ];
    }
}
