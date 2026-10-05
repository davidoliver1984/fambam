<?php

namespace App\Http\Requests;

use App\Enums\FamilySpaceDefaultVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFamilySpaceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $updates = [];
        if ($this->has('name')) {
            $updates['name'] = preg_replace('/\s+/u', ' ', trim((string) $this->input('name')));
        }
        if ($this->has('description')) {
            $description = trim((string) $this->input('description'));
            $updates['description'] = $description === '' ? null : $description;
        }
        $this->merge($updates);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_visibility' => ['sometimes', Rule::enum(FamilySpaceDefaultVisibility::class)],
        ];
    }
}
