<?php

namespace App\PhotoEditing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EditRecipe
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $rules = [
            'schema_version' => ['required', 'integer', 'in:1'],
            'crop' => ['present', 'nullable', 'array:x,y,width,height'],
            'crop.x' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.y' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.width' => ['required_with:crop', 'numeric', 'gt:0', 'lte:1'],
            'crop.height' => ['required_with:crop', 'numeric', 'gt:0', 'lte:1'],
            'rotate_degrees' => ['required', 'integer', 'in:0,90,180,270'],
            'flip_horizontal' => ['required', 'boolean'],
            'flip_vertical' => ['required', 'boolean'],
            'straighten_degrees' => ['required', 'numeric', 'between:-15,15'],
            'adjustments' => ['required', 'array:brightness,contrast,saturation,warmth', 'size:4'],
            'adjustments.brightness' => ['required', 'numeric', 'between:-100,100'],
            'adjustments.contrast' => ['required', 'numeric', 'between:-100,100'],
            'adjustments.saturation' => ['required', 'numeric', 'between:-100,100'],
            'adjustments.warmth' => ['required', 'numeric', 'between:-100,100'],
            'filter' => ['required', 'array:name,intensity', 'size:2'],
            'filter.name' => ['present', 'nullable', 'in:black_and_white,sepia,warm,cool,sharpen'],
            'filter.intensity' => ['required', 'numeric', 'between:0,100'],
        ];
        $data = Validator::make($input, $rules)->validate();
        if (count($input) !== 8) {
            throw ValidationException::withMessages(['edit_recipe' => 'The edit recipe contains unsupported fields.']);
        }
        $crop = $data['crop'];
        if ($crop !== null && ($crop['x'] + $crop['width'] > 1 || $crop['y'] + $crop['height'] > 1)) {
            throw ValidationException::withMessages(['edit_recipe.crop' => 'The crop must fit within the Photo.']);
        }
        if ($data['filter']['name'] === null && (float) $data['filter']['intensity'] !== 0.0) {
            throw ValidationException::withMessages(['edit_recipe.filter' => 'An unnamed filter must have zero intensity.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function identity(): array
    {
        return ['schema_version' => 1, 'crop' => null, 'rotate_degrees' => 0,
            'flip_horizontal' => false, 'flip_vertical' => false, 'straighten_degrees' => 0,
            'adjustments' => ['brightness' => 0, 'contrast' => 0, 'saturation' => 0, 'warmth' => 0],
            'filter' => ['name' => null, 'intensity' => 0]];
    }
}
