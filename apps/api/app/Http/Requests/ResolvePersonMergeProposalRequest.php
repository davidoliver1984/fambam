<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolvePersonMergeProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'account_link_resolution' => [
                'sometimes',
                'nullable',
                Rule::in(['keep_survivor', 'keep_absorbed', 'remove_both']),
            ],
        ];
    }
}
