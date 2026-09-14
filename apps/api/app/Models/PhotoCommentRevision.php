<?php

namespace App\Models;

use App\Casts\RichTextDocumentCast;
use App\Stories\RichTextDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $body */
#[Fillable(['family_space_id', 'photo_comment_id', 'editor_id', 'revision', 'body'])]
class PhotoCommentRevision extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => RichTextDocumentCast::class.':'.RichTextDocument::COMMENT];
    }
}
