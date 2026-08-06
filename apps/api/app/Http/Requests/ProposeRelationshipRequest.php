<?php

namespace App\Http\Requests;

use App\Enums\RelationshipProposalAction;
use App\Enums\RelationshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProposeRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(RelationshipProposalAction::class)],
            'relationship_id' => ['sometimes', 'nullable', 'ulid'],
            'subject_person_id' => ['sometimes', 'nullable', 'ulid'],
            'related_person_id' => ['sometimes', 'nullable', 'ulid'],
            'type' => ['sometimes', 'nullable', Rule::enum(RelationshipType::class)],
            'context' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $action = $this->input('action');
            $needsRelationship = in_array($action, ['replace', 'remove', 'dispute'], true);
            $needsEdge = in_array($action, ['create', 'replace'], true);

            if ($needsRelationship && ! $this->filled('relationship_id')) {
                $validator->errors()->add('relationship_id', 'The existing relationship is required.');
            }
            foreach (['related_person_id', 'type'] as $field) {
                if ($needsEdge && ! $this->filled($field)) {
                    $validator->errors()->add($field, 'This field is required for the proposed relationship.');
                }
            }
        }];
    }
}
