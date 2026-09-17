<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $family_space_id
 * @property string $collection_id
 * @property string $photo_id
 * @property int $position
 * @property Photo|null $photo
 */
#[Fillable(['family_space_id', 'collection_id', 'photo_id', 'position'])]
class CollectionPhoto extends Model
{
    use HasUlids;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }
}
