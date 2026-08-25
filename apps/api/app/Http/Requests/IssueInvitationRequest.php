<?php

namespace App\Http\Requests;

use App\Enums\FamilySpaceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => [
                'required_without:event_id',
                Rule::enum(FamilySpaceRole::class)->except([FamilySpaceRole::Owner]),
            ],
            'event_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
