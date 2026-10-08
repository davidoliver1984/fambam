<?php

namespace App\Http\Requests;

use App\People\PersonListCriteria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ListPeopleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', 'in:az,za'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('people.maximum_page_size')],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'in:all,living,remembered'],
        ];
    }

    public function criteria(string $familySpaceId): PersonListCriteria
    {
        $validated = $this->validated();
        $term = isset($validated['q']) ? trim((string) $validated['q']) : '';

        return new PersonListCriteria(
            sort: $validated['sort'] ?? 'az',
            limit: (int) ($validated['limit'] ?? config('people.default_page_size')),
            cursor: $validated['cursor'] ?? null,
            term: $term === '' ? null : Str::lower($term),
            status: $validated['status'] ?? 'all',
            scope: $familySpaceId,
        );
    }
}
