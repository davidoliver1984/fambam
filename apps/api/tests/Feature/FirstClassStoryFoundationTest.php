<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use App\Stories\RichTextRenderer;
use App\Stories\StoryHeading;
use App\Stories\StoryLifecycleManager;
use App\Stories\StoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FirstClassStoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_story_documents_assign_stable_mentions_and_keep_plain_text_in_sync(): void
    {
        [$family, $author, $photo, $person] = $this->subjectFixture();
        $writer = app(StoryWriter::class);
        $story = $writer->create($family->id, $author, ['photo_id' => $photo->id], $this->document($person),
            fn (string $id): bool => $id === $person->id);

        $mentionId = $story->body['blocks'][1]['content'][0]['mention_id'];
        $this->assertSame("Remembering \n\n{$person->preferred_name}", $story->body_plain_text);
        $this->assertDatabaseHas('story_person_mentions', [
            'story_id' => $story->id,
            'mention_id' => $mentionId,
            'person_id' => $person->id,
        ]);

        $other = Person::factory()->create(['family_space_id' => $family->id]);
        DB::table('story_person_mentions')->where('mention_id', $mentionId)->update(['person_id' => $other->id]);
        $stale = $story->body;
        $stale['blocks'][0]['content'][0]['text'] = 'Still remembering ';
        $updated = $writer->update($story, $author, $stale, fn (): bool => false);

        $this->assertSame($mentionId, $updated->body['blocks'][1]['content'][0]['mention_id']);
        $this->assertSame($person->id, $updated->body['blocks'][1]['content'][0]['person_id']);
        $this->assertSame($person->preferred_name, $updated->body['blocks'][1]['content'][0]['label']);
        $this->assertDatabaseHas('story_person_mentions', ['mention_id' => $mentionId, 'person_id' => $other->id]);
        $this->assertDatabaseHas('story_revisions', ['story_id' => $story->id, 'revision' => 1]);
    }

    public function test_document_boundary_rejects_unknown_structure_and_duplicate_continuing_mentions(): void
    {
        [$family, $author, $photo, $person] = $this->subjectFixture();
        $writer = app(StoryWriter::class);

        try {
            $writer->create($family->id, $author, ['photo_id' => $photo->id], [
                'schema_version' => 1,
                'blocks' => [['type' => 'html', 'content' => '<script>alert(1)</script>']],
            ], fn (): bool => true);
            $this->fail('Invalid rich text was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('stories', 0);
        }

        $story = $writer->create($family->id, $author, ['photo_id' => $photo->id], $this->document($person),
            fn (): bool => true);
        $mention = $story->body['blocks'][1]['content'][0];
        $duplicate = $story->body;
        $duplicate['blocks'][1]['content'][] = $mention;
        $this->expectException(ValidationException::class);
        $writer->update($story, $author, $duplicate, fn (): bool => true);
    }

    public function test_renderer_escapes_text_and_links_only_authorized_resolved_mentions(): void
    {
        [, , , $person] = $this->subjectFixture();
        $document = $this->document($person);
        $document['blocks'][0]['content'][0]['text'] = '<script>alert(1)</script>';
        $document['blocks'][1]['content'][0]['mention_id'] = '01AAAAAAAAAAAAAAAAAAAAAAAA';
        $renderer = app(RichTextRenderer::class);

        $html = $renderer->html($document, fn (): array => [
            'label' => 'William & family',
            'url' => '/people/01?next="bad"',
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('William &amp; family', $html);
        $this->assertStringContainsString('&quot;bad&quot;', $html);

        $fallback = $renderer->html($document, fn (string $mentionId): null => null);
        $this->assertStringContainsString('William Mercer', $fallback);
        $this->assertStringNotContainsString('<a ', $fallback);
    }

    public function test_story_heading_prefers_a_heading_and_resolves_current_mention_labels(): void
    {
        [, , , $person] = $this->subjectFixture();
        $document = $this->document($person);
        $document['blocks'][0] = ['type' => 'heading_2', 'content' => [[
            'type' => 'mention', 'mention_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
            'person_id' => $person->id, 'label' => 'Historical name',
        ]]];

        $this->assertSame('Current name', app(StoryHeading::class)->derive(
            $document,
            fn (string $mentionId): string => 'Current name',
        ));
    }

    public function test_story_deletion_restores_only_comments_deleted_by_the_same_operation(): void
    {
        [$family, $author, $photo, $person] = $this->subjectFixture();
        $writer = app(StoryWriter::class);
        $story = $writer->create($family->id, $author, ['photo_id' => $photo->id], $this->document($person),
            fn (): bool => true);
        $active = $writer->comment($story, $author, $this->commentDocument(), fn (): bool => true);
        $independent = $writer->comment($story, $author, $this->commentDocument(), fn (): bool => true);
        $independent->delete();

        $lifecycle = app(StoryLifecycleManager::class);
        $lifecycle->delete($story);
        $operation = Story::withTrashed()->findOrFail($story->id)->deletion_operation_id;
        $this->assertNotNull($operation);
        $this->assertSame($operation, StoryComment::withTrashed()->findOrFail($active->id)->deleted_with_story_operation_id);
        $this->assertNull(StoryComment::withTrashed()->findOrFail($independent->id)->deleted_with_story_operation_id);

        $lifecycle->restore($story);
        $this->assertFalse(StoryComment::withTrashed()->findOrFail($active->id)->trashed());
        $this->assertTrue(StoryComment::withTrashed()->findOrFail($independent->id)->trashed());
        $this->assertNull(Story::findOrFail($story->id)->deletion_operation_id);
    }

    public function test_immediate_delete_restore_cycles_use_distinct_operation_ids_and_ignore_stale_markers(): void
    {
        [$family, $author, $photo, $person] = $this->subjectFixture();
        $writer = app(StoryWriter::class);
        $story = $writer->create($family->id, $author, ['photo_id' => $photo->id], $this->document($person), fn (): bool => true);
        $current = $writer->comment($story, $author, $this->commentDocument(), fn (): bool => true);
        $lifecycle = app(StoryLifecycleManager::class);

        $lifecycle->delete($story);
        $firstOperation = Story::withTrashed()->findOrFail($story->id)->deletion_operation_id;
        $lifecycle->restore($story);
        $stale = $writer->comment($story->refresh(), $author, $this->commentDocument(), fn (): bool => true);
        $stale->delete();
        StoryComment::withTrashed()->whereKey($stale->id)->update(['deleted_with_story_operation_id' => $firstOperation]);

        $lifecycle->delete($story->refresh());
        $secondOperation = Story::withTrashed()->findOrFail($story->id)->deletion_operation_id;
        $this->assertNotSame($firstOperation, $secondOperation);
        $this->assertSame($secondOperation, StoryComment::withTrashed()->findOrFail($current->id)->deleted_with_story_operation_id);

        $lifecycle->restore($story);
        $this->assertFalse(StoryComment::withTrashed()->findOrFail($current->id)->trashed());
        $this->assertTrue(StoryComment::withTrashed()->findOrFail($stale->id)->trashed());
        $this->assertSame($firstOperation, StoryComment::withTrashed()->findOrFail($stale->id)->deleted_with_story_operation_id);
    }

    public function test_legacy_photo_story_schema_is_removed_after_cutover(): void
    {
        $this->assertFalse(Schema::hasTable('photo_stories'));
        $this->assertFalse(Schema::hasTable('photo_story_revisions'));
        $this->assertTrue(Schema::hasTable('stories'));
        $this->assertTrue(Schema::hasTable('story_revisions'));
    }

    /** @return array{FamilySpace, User, Photo, Person} */
    private function subjectFixture(): array
    {
        $family = FamilySpace::factory()->create();
        $author = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $author->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'William Mercer']);

        return [$family, $author, $photo, $person];
    }

    /** @return array<string, mixed> */
    private function document(Person $person): array
    {
        return ['schema_version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Remembering ']]],
            ['type' => 'paragraph', 'content' => [[
                'type' => 'mention', 'person_id' => $person->id, 'label' => $person->preferred_name,
            ]]],
        ]];
    }

    /** @return array<string, mixed> */
    private function commentDocument(): array
    {
        return ['schema_version' => 1, 'blocks' => [[
            'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'I remember this.']],
        ]]];
    }
}
