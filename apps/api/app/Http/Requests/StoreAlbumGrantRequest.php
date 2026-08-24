<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAlbumGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'string', 'size:26'],
            'can_view' => ['required', 'accepted'],
            'can_contribute' => ['required', 'boolean'],
        ];
    }
}
