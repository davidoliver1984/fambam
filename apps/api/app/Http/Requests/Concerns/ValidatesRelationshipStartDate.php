<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DatePrecision;
use App\People\UncertainDate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

trait ValidatesRelationshipStartDate
{
    /** @return array<string, list<mixed>> */
    private function relationshipStartDateRules(): array
    {
        return [
            'relationship_started_on' => ['sometimes', 'array'],
            'relationship_started_on.precision' => ['required_with:relationship_started_on', Rule::enum(DatePrecision::class)],
            'relationship_started_on.value' => ['present_with:relationship_started_on', 'nullable', 'string', 'max:10'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('relationship_started_on') || ! is_array($this->input('relationship_started_on'))) {
                return;
            }

            try {
                /** @var array{precision: string, value?: string|null} $input */
                $input = $this->input('relationship_started_on');
                UncertainDate::fromInput($input);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('relationship_started_on.value', $exception->getMessage());
            }
        }];
    }
}
