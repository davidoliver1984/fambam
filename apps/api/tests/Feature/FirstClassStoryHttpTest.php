<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Jobs\ProcessNotificationCandidate;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FirstClassStoryHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_api_creates_and_reads_every_typed_story_subject(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'first-class-stories']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family event']);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);

        foreach (['person' => $person->id, 'album' => $album->id, 'event' => $event->id, 'photo' => $photo->id] as $type => $id) {
            $response = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/stories", [
                'subject_type' => $type,
                'subject_id' => $id,
                'body' => $this->document("A {$type} memory."),
            ])->assertCreated()
                ->assertJsonPath('data.subject.type', $type)
                ->assertJsonPath('data.subject.id', $id)
                ->assertJsonPath('data.body_plain_text', "A {$type} memory.");

            $this->actingAs($owner)
                ->getJson("/api/families/{$family->slug}/stories/{$response->json('data.id')}")
                ->assertOk()->assertJsonPath('data.subject.type', $type);
        }

        $this->assertDatabaseCount('stories', 4);
        Queue::assertPushed(ProcessNotificationCandidate::class, 4);
    }

    public function test_story_comment_can_be_edited_only_by_its_author(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'story-comments']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $other = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $story = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $author->id,
            'photo_id' => $photo->id, 'body' => $this->document('Story'), 'body_plain_text' => 'Story']);

        $commentId = $this->actingAs($author)
            ->postJson("/api/families/{$family->slug}/stories/{$story->id}/comments", [
                'body' => $this->document('First comment.'),
            ])->assertCreated()->json('data.id');

        $this->actingAs($other)
            ->patchJson("/api/families/{$family->slug}/stories/{$story->id}/comments/{$commentId}", [
                'body' => $this->document('Not mine.'),
            ])->assertForbidden();
        $this->actingAs($author)
            ->patchJson("/api/families/{$family->slug}/stories/{$story->id}/comments/{$commentId}", [
                'body' => $this->document('Corrected comment.'),
            ])->assertOk()->assertJsonPath('data.body.blocks.0.content.0.text', 'Corrected comment.');
    }

    public function test_cross_family_person_cannot_be_used_as_a_story_subject(): void
    {
        Queue::fake();
        $first = FamilySpace::factory()->create(['slug' => 'first-story-family']);
        $second = FamilySpace::factory()->create(['slug' => 'second-story-family']);
        $owner = $this->member($first, FamilySpaceRole::Owner);
        $foreignPerson = Person::factory()->create(['family_space_id' => $second->id]);

        $this->actingAs($owner)->postJson("/api/families/{$first->slug}/stories", [
            'subject_type' => 'person',
            'subject_id' => $foreignPerson->id,
            'body' => $this->document('Not disclosed.'),
        ])->assertForbidden();

        $this->assertDatabaseCount('stories', 0);
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id, 'user_id' => $user->id,
            'role' => $role, 'state' => MembershipState::Active]);

        return $user;
    }

    /** @return array{schema_version: int, blocks: list<array<string, mixed>>} */
    private function document(string $text): array
    {
        return ['schema_version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }
}
