<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProposePersonMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'survivor_person_id' => ['required', 'ulid'],
            'context' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
