<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'family_space_id',
    'person_id',
    'label',
    'position',
    'created_by',
])]
class PersonKnownFor extends Model
{
    use HasUlids;

    protected $table = 'person_known_for';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
