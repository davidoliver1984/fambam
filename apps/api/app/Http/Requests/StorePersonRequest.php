<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPersonDetails;
use Illuminate\Foundation\Http\FormRequest;

class StorePersonRequest extends FormRequest
{
    use ValidatesPersonDetails;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->personDetailRules(requireName: true);
    }
}
