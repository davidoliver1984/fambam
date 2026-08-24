<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_space_id', 'album_id', 'family_space_membership_id', 'can_view', 'can_contribute', 'granted_by'])]
class AlbumGrant extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<FamilySpaceMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(FamilySpaceMembership::class, 'family_space_membership_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['can_view' => 'boolean', 'can_contribute' => 'boolean'];
    }
}
