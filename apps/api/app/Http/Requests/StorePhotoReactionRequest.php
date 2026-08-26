<?php

namespace App\Http\Requests;

use App\Enums\PhotoReactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhotoReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reaction' => ['required', Rule::enum(PhotoReactionType::class)],
            'album_id' => ['required', 'string', 'size:26'],
        ];
    }
}
