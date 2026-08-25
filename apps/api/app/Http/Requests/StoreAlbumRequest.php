<?php

namespace App\Http\Requests;

use App\Enums\AlbumVisibility;
use App\Enums\GuestParticipation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlbumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['sometimes', Rule::enum(AlbumVisibility::class)],
            'event_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'guest_participation' => ['sometimes', Rule::enum(GuestParticipation::class)],
        ];
    }
}
