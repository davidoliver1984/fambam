<?php

namespace App\Stories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MentionSynchronizer
{
    /**
     * @param  array<string, mixed>  $document
     * @param  callable(string): bool  $mayMention
     * @return array<string, mixed>
     */
    public function synchronize(
        Model $owner,
        string $table,
        string $ownerColumn,
        array $document,
        callable $mayMention,
    ): array {
        $existing = DB::table($table)->where($ownerColumn, $owner->getKey())
            ->lockForUpdate()->get()->keyBy('mention_id');
        $recognized = [];
        $rows = [];

        foreach ($document['blocks'] as &$block) {
            if (! isset($block['content'])) {
                continue;
            }
            foreach ($block['content'] as &$inline) {
                if (($inline['type'] ?? null) !== 'mention') {
                    continue;
                }
                $submittedId = $inline['mention_id'] ?? null;
                if (is_string($submittedId) && $existing->has($submittedId)) {
                    if (isset($recognized[$submittedId])) {
                        throw ValidationException::withMessages(['body' => 'A continuing mention may occur only once.']);
                    }
                    $recognized[$submittedId] = true;

                    continue;
                }
                if (! $mayMention($inline['person_id'])) {
                    throw ValidationException::withMessages(['body' => 'The selected Person cannot be mentioned here.']);
                }
                $inline['mention_id'] = (string) Str::ulid();
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'family_space_id' => $owner->getAttribute('family_space_id'),
                    $ownerColumn => $owner->getKey(),
                    'mention_id' => $inline['mention_id'],
                    'person_id' => $inline['person_id'],
                    'historical_label_snapshot' => $inline['label'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            unset($inline);
        }
        unset($block);

        DB::table($table)->where($ownerColumn, $owner->getKey())
            ->whereNotIn('mention_id', array_keys($recognized))->delete();
        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }

        return $document;
    }
}
