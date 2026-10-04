<?php

namespace App\Http\Requests;

use App\Albums\AlbumListCriteria;
use Illuminate\Foundation\Http\FormRequest;

class ListAlbumsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', 'in:newest,oldest,updated'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('albums.maximum_page_size')],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'q' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'tag_id' => ['nullable', 'string', 'size:26'],
            'person_ids' => ['nullable', 'array', 'max:20'],
            'person_ids.*' => ['string', 'size:26', 'distinct'],
            'event_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function criteria(string $familySpaceId): AlbumListCriteria
    {
        $validated = $this->validated();
        $personIds = array_values($validated['person_ids'] ?? []);
        sort($personIds);

        return new AlbumListCriteria(
            sort: $validated['sort'] ?? 'newest',
            limit: (int) ($validated['limit'] ?? config('albums.default_page_size')),
            cursor: $validated['cursor'] ?? null,
            term: $this->nullableTrimmed($validated['q'] ?? null),
            location: $this->nullableTrimmed($validated['location'] ?? null),
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            tagId: $validated['tag_id'] ?? null,
            personIds: $personIds,
            eventId: $validated['event_id'] ?? null,
            scope: $familySpaceId,
        );
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
