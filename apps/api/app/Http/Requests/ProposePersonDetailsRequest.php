<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPersonDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProposePersonDetailsRequest extends FormRequest
{
    use ValidatesPersonDetails {
        after as personDateValidation;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->personDetailRules();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            ...$this->personDateValidation(),
            function (Validator $validator): void {
                if (! $this->hasAny([
                    'preferred_name',
                    'alternate_names',
                    'birth_date',
                    'is_deceased',
                    'death_date',
                    'biography',
                ])) {
                    $validator->errors()->add('changes', 'At least one Person detail must be proposed.');
                }
            },
        ];
    }
}
