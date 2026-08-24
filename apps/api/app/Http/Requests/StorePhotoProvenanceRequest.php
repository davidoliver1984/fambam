<?php

namespace App\Http\Requests;

use App\Enums\PhotoProvenanceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePhotoProvenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(PhotoProvenanceRole::class)],
            'person_id' => ['nullable', 'string', 'size:26'],
            'description' => ['nullable', 'string', 'max:255'],
            'clears_claim' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $personId = $this->input('person_id');
            $description = trim((string) $this->input('description', ''));
            $clears = $this->boolean('clears_claim');
            $valueCount = ($personId === null ? 0 : 1) + ($description === '' ? 0 : 1);

            if (($clears && $valueCount !== 0) || (! $clears && $valueCount !== 1)) {
                $validator->errors()->add(
                    'provenance',
                    'Choose exactly one Person or free-text value, or explicitly clear the claim.',
                );
            }
        }];
    }
}
