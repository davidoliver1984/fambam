<?php

namespace App\Http\Requests;

use App\Enums\AlbumVisibility;
use App\Enums\GuestParticipation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlbumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable'],
            'visibility' => ['sometimes', Rule::enum(AlbumVisibility::class)],
            'event_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'guest_participation' => ['sometimes', Rule::enum(GuestParticipation::class)],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array', 'max:25'],
            'tags.*' => ['string', 'max:80'],
            'person_ids' => ['sometimes', 'array', 'max:100'],
            'person_ids.*' => ['ulid', 'distinct'],
        ];
    }
}
