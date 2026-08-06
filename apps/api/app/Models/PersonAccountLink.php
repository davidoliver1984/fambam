<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_space_id', 'person_id', 'user_id', 'created_by'])]
class PersonAccountLink extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
