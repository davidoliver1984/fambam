<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeFaceClustersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cluster_ids' => ['required', 'array', 'min:2'],
            'cluster_ids.*' => ['required', 'string', 'distinct', 'exists:face_clusters,id'],
        ];
    }
}
