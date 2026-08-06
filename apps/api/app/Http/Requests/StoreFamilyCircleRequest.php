<?php

namespace App\Http\Requests;

use App\Models\FamilySpace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFamilyCircleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $familySpace = $this->route('familySpace');
        $familySpaceId = $familySpace instanceof FamilySpace ? $familySpace->id : '';

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                Rule::unique('family_circles', 'name')
                    ->where('family_space_id', $familySpaceId)
                    ->ignore($this->route('circle')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
