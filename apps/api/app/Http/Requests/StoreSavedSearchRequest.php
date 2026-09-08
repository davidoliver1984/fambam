<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavedSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'filters' => ['required', 'array'],
            'filters.q' => ['nullable', 'string', 'max:200'],
            'filters.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filters.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'],
            'filters.tag_id' => ['nullable', 'string', 'size:26'],
            'filters.event_id' => ['nullable', 'string', 'size:26'],
            'filters.album_id' => ['nullable', 'string', 'size:26'],
            'filters.uploaded_by' => ['nullable', 'integer', 'min:1'],
            'filters.visibility' => ['nullable', 'string', 'in:family_space,selected,private'],
            'filters.person_ids' => ['nullable', 'array', 'max:20'],
            'filters.person_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
