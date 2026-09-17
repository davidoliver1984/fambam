<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetAlbumCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'photo_id' => ['present', 'nullable', 'ulid'],
            'focal_x' => ['sometimes', 'numeric', 'between:0,1'],
            'focal_y' => ['sometimes', 'numeric', 'between:0,1'],
            'confirm_visibility_widening' => ['sometimes', 'boolean'],
        ];
    }
}
