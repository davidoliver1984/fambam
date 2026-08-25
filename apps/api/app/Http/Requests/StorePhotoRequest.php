<?php

namespace App\Http\Requests;

use App\Enums\PhotoVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'media_upload_id' => ['required', 'string', 'size:26'],
            'visibility' => ['sometimes', Rule::enum(PhotoVisibility::class)],
            'caption' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'archive_source_description' => ['nullable', 'string', 'max:1000'],
            'primary_event_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['required', 'string', 'max:80'],
        ];
    }
}
