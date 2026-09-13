<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => [Rule::requiredIf($this->isMethod('post')), Rule::in(['person', 'album', 'event', 'photo'])],
            'subject_id' => [Rule::requiredIf($this->isMethod('post')), 'string', 'size:26'],
            'body' => ['required', 'array'],
        ];
    }
}
