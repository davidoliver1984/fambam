<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_space_id', 'family_circle_id', 'person_id', 'added_by'])]
class FamilyCirclePerson extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
