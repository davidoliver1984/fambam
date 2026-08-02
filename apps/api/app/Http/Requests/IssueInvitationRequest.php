<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-invitations') === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }
}
