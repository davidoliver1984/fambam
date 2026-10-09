<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DatePrecision;
use App\People\UncertainDate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

trait ValidatesPersonDetails
{
    /** @return array<string, list<mixed>> */
    private function personDetailRules(bool $requireName = false): array
    {
        return [
            'preferred_name' => [$requireName ? 'required' : 'sometimes', 'string', 'min:1', 'max:120'],
            'alternate_names' => ['sometimes', 'array', 'max:20'],
            'alternate_names.*' => ['string', 'min:1', 'max:120', 'distinct'],
            'birth_date' => ['sometimes', 'array'],
            'birth_date.precision' => ['required_with:birth_date', Rule::enum(DatePrecision::class)],
            'birth_date.value' => ['present_with:birth_date', 'nullable', 'string', 'max:10'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_deceased' => ['sometimes', 'boolean'],
            'death_date' => ['sometimes', 'array'],
            'death_date.precision' => ['required_with:death_date', Rule::enum(DatePrecision::class)],
            'death_date.value' => ['present_with:death_date', 'nullable', 'string', 'max:10'],
            'death_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'residence_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'biography' => ['sometimes', 'nullable'],
            'profile_quote' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'profile_quote_attribution' => ['sometimes', 'nullable', 'string', 'max:120'],
            'known_for' => ['sometimes', 'array', 'max:12'],
            'known_for.*' => ['string', 'min:1', 'max:120', 'distinct:ignore_case'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['birth_date', 'death_date'] as $field) {
                if (! $this->has($field) || ! is_array($this->input($field))) {
                    continue;
                }

                try {
                    /** @var array{precision: string, value?: string|null} $input */
                    $input = $this->input($field);
                    UncertainDate::fromInput($input);
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add("{$field}.value", $exception->getMessage());
                }
            }

            if ($this->has('known_for') && is_array($this->input('known_for'))) {
                $seen = [];
                foreach ($this->input('known_for') as $index => $label) {
                    $normalized = mb_strtolower(trim((string) $label));
                    if ($normalized === '') {
                        $validator->errors()->add("known_for.{$index}", 'Known-for labels may not be blank.');
                    } elseif (isset($seen[$normalized])) {
                        $validator->errors()->add("known_for.{$index}", 'Known-for labels must be unique.');
                    }
                    $seen[$normalized] = true;
                }
            }
        }];
    }
}
