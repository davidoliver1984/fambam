<?php

namespace App\Http\Requests;

use App\Photos\PhotoListCriteria;
use Illuminate\Foundation\Http\FormRequest;

final class ListPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', 'in:newest,oldest,recently_added'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('photos.maximum_page_size')],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'q' => ['nullable', 'string', 'max:200'],
            'person_id' => ['nullable', 'string', 'size:26'],
            'tag' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:255'],
            'historical_year' => ['nullable', 'integer', 'between:1,9999'],
            'without_confirmed_date' => ['nullable', 'boolean'],
            'without_album' => ['nullable', 'boolean'],
        ];
    }

    public function criteria(string $familySpaceId): PhotoListCriteria
    {
        $validated = $this->validated();

        return new PhotoListCriteria(
            sort: $validated['sort'] ?? 'newest',
            limit: (int) ($validated['limit'] ?? config('photos.default_page_size')),
            cursor: $validated['cursor'] ?? null,
            term: $this->nullableTrimmed($validated['q'] ?? null),
            personId: $validated['person_id'] ?? null,
            tag: $this->nullableTrimmed($validated['tag'] ?? null),
            location: $this->nullableTrimmed($validated['location'] ?? null),
            historicalYear: isset($validated['historical_year']) ? (int) $validated['historical_year'] : null,
            withoutConfirmedDate: filter_var($validated['without_confirmed_date'] ?? false, FILTER_VALIDATE_BOOL),
            withoutAlbum: filter_var($validated['without_album'] ?? false, FILTER_VALIDATE_BOOL),
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
