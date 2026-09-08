<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NameFaceClusterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'person_id' => ['required', 'string', 'exists:people,id'],
            'confirm' => ['required', 'boolean'],
        ];
    }
}
