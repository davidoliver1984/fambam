<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddCirclePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['person_id' => ['required', 'ulid']];
    }
}
