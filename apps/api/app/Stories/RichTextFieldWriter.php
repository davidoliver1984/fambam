<?php

namespace App\Stories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class RichTextFieldWriter
{
    public function __construct(
        private readonly RichTextDocument $documents,
        private readonly MentionSynchronizer $mentions,
    ) {}

    /**
     * @param  callable(string): bool  $mayMention
     * @return array<string, mixed>|null
     */
    public function synchronize(
        Model $owner,
        string $table,
        string $ownerColumn,
        mixed $value,
        callable $mayMention,
        string $vocabulary = RichTextDocument::FULL,
    ): ?array {
        $document = $this->documents->normalize($value, $vocabulary);
        if ($document === null) {
            DB::table($table)->where($ownerColumn, $owner->getKey())->delete();

            return null;
        }

        return $this->mentions->synchronize($owner, $table, $ownerColumn, $document, $mayMention);
    }
}
