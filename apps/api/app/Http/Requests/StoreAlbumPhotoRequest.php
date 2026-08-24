<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAlbumPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo_id' => ['required', 'string', 'size:26'],
            'confirm_visibility_widening' => ['sometimes', 'boolean'],
        ];
    }
}
