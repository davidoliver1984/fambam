<?php

namespace App\PhotoEditing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RestoreProvenance
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'algorithm_version' => ['required', 'string', 'in:restore-v1'],
            'processing_mode' => ['required', 'string', 'in:conservative'],
            'outcome' => ['required', 'string', 'in:applied'],
            'parameters' => ['required', 'array:white_balance_shift,exposure_adjustment,saturation_recovery,denoise_strength,sharpen_strength', 'size:5'],
            'parameters.white_balance_shift' => ['required', 'numeric', 'between:-5,5'],
            'parameters.exposure_adjustment' => ['required', 'numeric', 'between:0,12'],
            'parameters.saturation_recovery' => ['required', 'numeric', 'between:0,8'],
            'parameters.denoise_strength' => ['required', 'numeric', 'between:0,10'],
            'parameters.sharpen_strength' => ['required', 'numeric', 'between:0,10'],
        ])->validate();
        if (count($input) !== 5) {
            throw ValidationException::withMessages(['restore' => 'Restore provenance contains unsupported fields.']);
        }

        return $data;
    }
}
