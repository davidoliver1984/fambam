<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitFaceClusterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:2'],
            'groups.*' => ['required', 'array', 'min:1'],
            'groups.*.*' => ['required', 'string', 'distinct', 'exists:face_observations,id'],
        ];
    }
}
