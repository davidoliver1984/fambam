<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'survivor_person_id' => ['required', 'ulid'],
            'account_link_resolution' => [
                'sometimes',
                'nullable',
                Rule::in(['keep_survivor', 'keep_absorbed', 'remove_both']),
            ],
        ];
    }
}
