<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200', 'required_without_all:date_from,date_to,tag_id,person_ids,event_id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'tag_id' => ['nullable', 'string', 'size:26'],
            'person_ids' => ['nullable', 'array', 'max:20'],
            'person_ids.*' => ['string', 'size:26', 'distinct'],
            'event_id' => ['nullable', 'string', 'size:26'],
            'group' => ['nullable', 'string', 'in:people,photos,albums,events,stories'],
            'cursor' => [
                'nullable',
                'string',
                'max:2048',
                Rule::prohibitedIf(fn (): bool => ! $this->filled('group')),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('search.maximum_page_size')],
        ];
    }
}
