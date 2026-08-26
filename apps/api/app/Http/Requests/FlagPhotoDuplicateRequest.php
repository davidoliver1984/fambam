<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FlagPhotoDuplicateRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['candidate_photo_id' => ['required', 'string', 'size:26']];
    }
}
