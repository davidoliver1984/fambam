<?php

namespace App\Http\Requests;

use App\Enums\RelationshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'related_person_id' => ['required', 'ulid'],
            'type' => ['required', Rule::enum(RelationshipType::class)],
            'context' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
