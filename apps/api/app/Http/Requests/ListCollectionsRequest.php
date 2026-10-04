<?php

namespace App\Http\Requests;

use App\Collections\CollectionListCriteria;
use App\Enums\CollectionPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCollectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $purpose = $this->query('purpose');
        if (is_string($purpose)) {
            $this->merge(['purpose' => [$purpose]]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', Rule::in(['updated', 'name'])],
            'q' => ['nullable', 'string', 'max:200'],
            'collection_id' => ['nullable', 'string', 'size:26'],
            'purpose' => ['nullable', 'array', 'max:2'],
            'purpose.*' => ['string', 'distinct', Rule::enum(CollectionPurpose::class)],
        ];
    }

    public function criteria(): CollectionListCriteria
    {
        $validated = $this->validated();
        $purposes = array_map(
            fn (string $purpose): CollectionPurpose => CollectionPurpose::from($purpose),
            array_values($validated['purpose'] ?? []),
        );

        return new CollectionListCriteria(
            sort: $validated['sort'] ?? 'updated',
            term: $this->nullableTrimmed($validated['q'] ?? null),
            collectionId: $validated['collection_id'] ?? null,
            purposes: $purposes,
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
