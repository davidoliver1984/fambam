<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_space_id', 'photo_comment_id', 'editor_id', 'revision', 'body'])]
class PhotoCommentRevision extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
