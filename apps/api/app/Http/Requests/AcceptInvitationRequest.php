<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Password>> */
    public function rules(): array
    {
        return [
            'claim_token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['prohibited'],
            'password' => ['required', 'string', 'max:255', Password::min(15), 'confirmed'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
