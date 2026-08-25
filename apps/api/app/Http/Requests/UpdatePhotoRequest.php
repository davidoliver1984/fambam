<?php

namespace App\Http\Requests;

use App\Enums\PhotoVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'visibility' => ['sometimes', Rule::enum(PhotoVisibility::class)],
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'archive_source_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'primary_event_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
