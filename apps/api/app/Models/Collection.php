<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $family_space_id
 * @property int $owner_user_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $deleting_at
 * @property Carbon|null $created_at
 */
#[Fillable(['family_space_id', 'owner_user_id', 'name', 'description', 'deleting_at'])]
class Collection extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<CollectionPhoto, $this> */
    public function collectionPhotos(): HasMany
    {
        return $this->hasMany(CollectionPhoto::class)->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['deleting_at' => 'immutable_datetime'];
    }
}
