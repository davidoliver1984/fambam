<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'about' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('about')) {
            return;
        }

        $about = trim((string) $this->input('about'));
        $this->merge(['about' => $about === '' ? null : $about]);
    }
}
