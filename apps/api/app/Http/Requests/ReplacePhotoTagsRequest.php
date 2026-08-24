<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplacePhotoTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tags' => ['required', 'array', 'max:30'],
            'tags.*' => ['required', 'string', 'max:80'],
        ];
    }
}
