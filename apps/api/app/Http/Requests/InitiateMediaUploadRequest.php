<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateMediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_filename' => ['required', 'string', 'max:255'],
            'client_mime_type' => ['nullable', 'string', 'max:255'],
            'upload_batch_id' => ['nullable', 'ulid'],
        ];
    }
}
