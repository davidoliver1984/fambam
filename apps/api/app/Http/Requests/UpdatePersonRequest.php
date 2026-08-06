<?php

namespace App\Http\Requests;

use App\Enums\PersonIdentityStatus;
use App\Http\Requests\Concerns\ValidatesPersonDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonRequest extends FormRequest
{
    use ValidatesPersonDetails;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->personDetailRules(),
            'identity_status' => ['sometimes', Rule::enum(PersonIdentityStatus::class)],
        ];
    }
}
