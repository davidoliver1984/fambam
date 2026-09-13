<?php

namespace App\Stories;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Support\Facades\DB;

final class LegacyStoryBackfill
{
    public function __construct(private readonly DatabaseTenantContext $databaseTenant) {}

    /** @return array{stories: int, revisions: int} */
    public function run(): array
    {
        $stories = 0;
        $revisions = 0;
        $familySpaceIds = DB::table('family_spaces')->orderBy('id')->pluck('id');
        foreach ($familySpaceIds as $familySpaceId) {
            DB::transaction(function () use ($familySpaceId, &$stories, &$revisions): void {
                $this->databaseTenant->establishFamilySpace((string) $familySpaceId, authoritativeOperation: 'story_backfill');
                $this->backfillFamily((string) $familySpaceId, $stories, $revisions);
            });
        }

        return ['stories' => $stories, 'revisions' => $revisions];
    }

    private function backfillFamily(string $familySpaceId, int &$stories, int &$revisions): void
    {
        DB::table('photo_stories')->where('family_space_id', $familySpaceId)
            ->orderBy('id')->chunkById(200, function ($rows) use (&$stories): void {
                foreach ($rows as $row) {
                    $document = $this->wrap((string) $row->body);
                    $stories += DB::table('stories')->insertOrIgnore([
                        'id' => $row->id,
                        'family_space_id' => $row->family_space_id,
                        'author_id' => $row->author_id,
                        'person_id' => null,
                        'album_id' => null,
                        'event_id' => null,
                        'photo_id' => $row->photo_id,
                        'body' => json_encode($document, JSON_THROW_ON_ERROR),
                        'body_plain_text' => $row->body,
                        'edited_at' => $row->edited_at,
                        'deletion_operation_id' => null,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                        'deleted_at' => $row->deleted_at,
                    ]);
                }
            }, 'id');
        DB::table('photo_story_revisions')->where('family_space_id', $familySpaceId)
            ->orderBy('id')->chunkById(200, function ($rows) use (&$revisions): void {
                foreach ($rows as $row) {
                    $revisions += DB::table('story_revisions')->insertOrIgnore([
                        'id' => $row->id,
                        'family_space_id' => $row->family_space_id,
                        'story_id' => $row->photo_story_id,
                        'editor_id' => $row->editor_id,
                        'revision' => $row->revision,
                        'body' => json_encode($this->wrap((string) $row->body), JSON_THROW_ON_ERROR),
                        'created_at' => $row->created_at,
                    ]);
                }
            }, 'id');
    }

    /** @return array{schema_version: int, blocks: array<int, array<string, mixed>>} */
    private function wrap(string $body): array
    {
        return ['schema_version' => 1, 'blocks' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $body]],
        ]]];
    }
}
