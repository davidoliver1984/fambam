<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_space_id', 'photo_low_id', 'photo_high_id', 'source', 'decided_by', 'decided_at', 'reopened_by', 'reopened_at'])]
class DuplicateDecision extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'reopened_at' => 'immutable_datetime'];
    }
}
