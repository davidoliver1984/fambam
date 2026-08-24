<?php

namespace App\Http\Requests;

use App\Enums\PhotoMetadataField;
use App\People\UncertainDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StorePhotoMetadataProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field' => ['required', Rule::enum(PhotoMetadataField::class)],
            'date' => ['nullable', 'array'],
            'date.precision' => ['nullable', 'string'],
            'date.value' => ['nullable', 'string', 'max:10'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'clears_claim' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $field = PhotoMetadataField::tryFrom((string) $this->input('field'));
            $clears = $this->boolean('clears_claim');
            $date = $this->input('date');
            $location = trim((string) $this->input('location_description', ''));

            if ($clears) {
                if ($date !== null || $location !== '') {
                    $validator->errors()->add('metadata', 'A clear proposal cannot contain a value.');
                }

                return;
            }

            if ($field === PhotoMetadataField::HistoricalDate) {
                if (! is_array($date) || $location !== '') {
                    $validator->errors()->add('date', 'Provide one historical date only.');

                    return;
                }

                try {
                    /** @var array{precision: string, value?: string|null} $date */
                    UncertainDate::fromInput($date);
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('date', $exception->getMessage());
                }

                return;
            }

            if ($field === PhotoMetadataField::Location && ($date !== null || $location === '')) {
                $validator->errors()->add('location_description', 'Provide one human-supplied location only.');
            }
        }];
    }
}
