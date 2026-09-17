<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $family_space_id
 * @property string $photo_id
 * @property array<string, mixed> $edit_recipe
 * @property array<string, mixed>|null $restore
 * @property string $derived_object_key
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['id', 'family_space_id', 'photo_id', 'edit_recipe', 'restore', 'derived_object_key', 'created_by'])]
class PhotoVersion extends Model
{
    use HasUlids;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edit_recipe' => 'array', 'restore' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
