<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaVariantTransform;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Jobs\ProcessNotificationCandidate;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaVariant;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FirstClassStoryHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new StoryReadModelUrlSigner);
    }

    public function test_canonical_api_creates_and_reads_every_typed_story_subject(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'first-class-stories']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'preferred_name' => 'Ada Mercer']);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family event']);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'caption' => 'Camera on the pier']);

        foreach (['person' => [$person->id, 'Ada Mercer'], 'album' => [$album->id, 'Family album'],
            'event' => [$event->id, 'Family event'], 'photo' => [$photo->id, 'Camera on the pier']] as $type => [$id, $label]) {
            $response = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/stories", [
                'subject_type' => $type,
                'subject_id' => $id,
                'body' => $this->document("A {$type} memory."),
            ])->assertCreated()
                ->assertJsonPath('data.subject.type', $type)
                ->assertJsonPath('data.subject.id', $id)
                ->assertJsonPath('data.subject.label', $label)
                ->assertJsonPath('data.body_plain_text', "A {$type} memory.");

            $this->actingAs($owner)
                ->getJson("/api/families/{$family->slug}/stories/{$response->json('data.id')}")
                ->assertOk()->assertJsonPath('data.subject.type', $type);
        }

        $this->assertDatabaseCount('stories', 4);
        Queue::assertPushed(ProcessNotificationCandidate::class, 4);
    }

    public function test_story_and_comment_authors_use_authorized_person_presentations_without_cross_family_leakage(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'story-actor-presentation']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $commenter = $this->member($family, FamilySpaceRole::Member);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $author->update(['name' => 'Account Author']);
        $commenter->update(['name' => 'Comment Account']);
        $authorPerson = Person::factory()->create(['family_space_id' => $family->id,
            'preferred_name' => 'Alice Mercer']);
        $commentPerson = Person::factory()->create(['family_space_id' => $family->id,
            'preferred_name' => 'Bob Mercer']);
        foreach ([[$author, $authorPerson], [$commenter, $commentPerson]] as [$user, $person]) {
            PersonAccountLink::query()->create(['family_space_id' => $family->id, 'person_id' => $person->id,
                'user_id' => $user->id, 'created_by' => $viewer->id]);
        }
        $portrait = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $portrait->photoPeople()->create(['family_space_id' => $family->id, 'person_id' => $authorPerson->id,
            'proposal_source' => 'human', 'status' => 'approved', 'proposed_by' => $author->id,
            'resolved_by' => $author->id, 'resolved_at' => now()]);
        MediaVariant::query()->create(['family_space_id' => $family->id,
            'media_upload_id' => $portrait->media_upload_id, 'transform_name' => MediaVariantTransform::Thumbnail,
            'processing_version' => (int) config('media.processing.variant_processing_version'),
            'object_key' => "families/{$family->id}/author-thumbnail.webp", 'mime_type' => 'image/webp',
            'sha256' => hash('sha256', 'author'), 'pixel_width' => 320, 'pixel_height' => 320, 'byte_size' => 100]);
        $privatePortrait = Photo::factory()->create(['family_space_id' => $family->id,
            'created_by' => $commenter->id, 'visibility' => PhotoVisibility::Private]);
        $privatePortrait->photoPeople()->create(['family_space_id' => $family->id, 'person_id' => $commentPerson->id,
            'proposal_source' => 'human', 'status' => 'approved', 'proposed_by' => $commenter->id,
            'resolved_by' => $commenter->id, 'resolved_at' => now()]);
        MediaVariant::query()->create(['family_space_id' => $family->id,
            'media_upload_id' => $privatePortrait->media_upload_id, 'transform_name' => MediaVariantTransform::Thumbnail,
            'processing_version' => (int) config('media.processing.variant_processing_version'),
            'object_key' => "families/{$family->id}/private-thumbnail.webp", 'mime_type' => 'image/webp',
            'sha256' => hash('sha256', 'private'), 'pixel_width' => 320, 'pixel_height' => 320, 'byte_size' => 100]);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $story = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $author->id,
            'photo_id' => $photo->id, 'body' => $this->document('Story'), 'body_plain_text' => 'Story']);
        StoryComment::query()->create(['family_space_id' => $family->id, 'story_id' => $story->id,
            'author_id' => $commenter->id, 'body' => $this->document('Comment')]);

        $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/stories/{$story->id}")
            ->assertOk()
            ->assertJsonPath('data.author.display_name', 'Alice Mercer')
            ->assertJsonPath('data.author.person_id', $authorPerson->id)
            ->assertJsonPath('data.author.initials', 'AM')
            ->assertJsonPath('data.author.portrait_thumbnail_url', fn (?string $url): bool => $url !== null
                && str_contains($url, 'author-thumbnail.webp'))
            ->assertJsonPath('data.comments.0.author.display_name', 'Bob Mercer')
            ->assertJsonPath('data.comments.0.author.person_id', $commentPerson->id)
            ->assertJsonPath('data.comments.0.author.initials', 'BM')
            ->assertJsonPath('data.comments.0.author.portrait_thumbnail_url', null);

        $foreignFamily = FamilySpace::factory()->create(['slug' => 'foreign-story-actor']);
        $foreignPerson = Person::factory()->create(['family_space_id' => $foreignFamily->id,
            'preferred_name' => 'Foreign Identity']);
        PersonAccountLink::query()->create(['family_space_id' => $foreignFamily->id, 'person_id' => $foreignPerson->id,
            'user_id' => $author->id, 'created_by' => $viewer->id]);

        $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/stories/{$story->id}")
            ->assertOk()->assertJsonMissing(['display_name' => 'Foreign Identity']);
    }

    public function test_story_document_round_trips_structure_and_typed_mentions_without_plain_text_flattening(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'structured-story-edit']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $first = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Ada Mercer']);
        $second = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Bob Mercer']);
        $story = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $author->id,
            'photo_id' => $photo->id, 'body' => $this->document('Original'), 'body_plain_text' => 'Original']);
        $document = ['schema_version' => 1, 'blocks' => [
            ['type' => 'heading_2', 'content' => [['type' => 'text', 'text' => 'Family day', 'marks' => ['bold']]]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'With ', 'marks' => ['italic']],
                ['type' => 'mention', 'person_id' => $first->id, 'label' => 'Ada Mercer'],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'mention', 'person_id' => $second->id, 'label' => 'Bob Mercer'],
            ]],
            ['type' => 'horizontal_rule'],
        ]];

        $response = $this->actingAs($author)
            ->patchJson("/api/families/{$family->slug}/stories/{$story->id}", ['body' => $document])
            ->assertOk()
            ->assertJsonPath('data.body.blocks.0.type', 'heading_2')
            ->assertJsonPath('data.body.blocks.0.content.0.marks.0', 'bold')
            ->assertJsonPath('data.body.blocks.1.content.0.marks.0', 'italic')
            ->assertJsonPath('data.body.blocks.2.type', 'horizontal_rule')
            ->assertJsonPath('data.body_plain_text', "Family day\n\nWith Ada Mercer and Bob Mercer");
        $this->assertNotNull($response->json('data.body.blocks.1.content.1.mention_id'));
        $this->assertNotNull($response->json('data.body.blocks.1.content.3.mention_id'));

        $this->actingAs($author)->getJson("/api/families/{$family->slug}/stories/{$story->id}")
            ->assertOk()->assertJsonPath('data.body.blocks.2.type', 'horizontal_rule');
        $this->assertDatabaseCount('story_person_mentions', 2);
    }

    public function test_comment_mentions_use_contextual_authorized_suggestions_and_persist_typed_identity(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'story-comment-mentions']);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $contributor->id]);
        $visible = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Ada Visible']);
        $hidden = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Alice Hidden']);
        $photo->photoPeople()->create(['family_space_id' => $family->id, 'person_id' => $visible->id,
            'proposal_source' => 'human', 'status' => 'approved', 'proposed_by' => $contributor->id,
            'resolved_by' => $contributor->id, 'resolved_at' => now()]);
        $story = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $contributor->id,
            'photo_id' => $photo->id, 'body' => $this->document('Story'), 'body_plain_text' => 'Story']);

        $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/stories/{$story->id}/mention-suggestions?prefix=A")
            ->assertOk()->assertJsonFragment(['id' => $visible->id, 'label' => 'Ada Visible'])
            ->assertJsonMissing(['id' => $hidden->id]);

        $body = ['schema_version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'mention', 'person_id' => $visible->id, 'label' => 'Ada Visible'],
        ]]]];
        $response = $this->actingAs($contributor)
            ->postJson("/api/families/{$family->slug}/stories/{$story->id}/comments", ['body' => $body])
            ->assertCreated()->assertJsonPath('data.body.blocks.0.content.1.person_id', $visible->id);
        $mentionId = $response->json('data.body.blocks.0.content.1.mention_id');
        $this->assertDatabaseHas('story_comment_person_mentions', [
            'story_comment_id' => $response->json('data.id'), 'mention_id' => $mentionId, 'person_id' => $visible->id,
        ]);

        $unauthorized = $body;
        $unauthorized['blocks'][0]['content'][1]['person_id'] = $hidden->id;
        $unauthorized['blocks'][0]['content'][1]['label'] = 'Alice Hidden';
        $this->actingAs($contributor)
            ->postJson("/api/families/{$family->slug}/stories/{$story->id}/comments", ['body' => $unauthorized])
            ->assertUnprocessable();
    }

    public function test_story_hero_uses_existing_subject_media_conventions_without_persistence(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'story-hero']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Covered album', 'visibility' => AlbumVisibility::FamilySpace, 'cover_photo_id' => $photo->id]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Preview event']);
        $eventPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'primary_event_id' => $event->id, 'historical_date' => '1950-01-01']);
        $earliestEventPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'primary_event_id' => $event->id, 'historical_date' => '1940-01-01']);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Portrait Person']);
        $photo->photoPeople()->create(['family_space_id' => $family->id, 'person_id' => $person->id,
            'proposal_source' => 'human', 'status' => 'approved', 'proposed_by' => $owner->id,
            'resolved_by' => $owner->id, 'resolved_at' => now()]);
        MediaVariant::query()->create(['family_space_id' => $family->id,
            'media_upload_id' => $photo->media_upload_id, 'transform_name' => MediaVariantTransform::Thumbnail,
            'processing_version' => (int) config('media.processing.variant_processing_version'),
            'object_key' => "families/{$family->id}/portrait-thumbnail.webp", 'mime_type' => 'image/webp',
            'sha256' => hash('sha256', 'portrait'), 'pixel_width' => 320, 'pixel_height' => 320, 'byte_size' => 100]);

        foreach (['photo' => [$photo->id, 'photo', $photo->id],
            'album' => [$album->id, 'album_cover', $photo->id],
            'event' => [$event->id, 'event_preview', $earliestEventPhoto->id],
            'person' => [$person->id, 'person_portrait', null]] as $type => [$subjectId, $sourceType, $photoId]) {
            $story = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $owner->id,
                "{$type}_id" => $subjectId, 'body' => $this->document('Story'), 'body_plain_text' => 'Story']);
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/stories/{$story->id}")
                ->assertOk()->assertJsonPath('data.hero.source_type', $sourceType)
                ->assertJsonPath('data.hero.photo_id', $photoId);
        }

        $viewer = $this->member($family, FamilySpaceRole::Member);
        $privateCover = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private]);
        $privateAlbum = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Private cover album', 'visibility' => AlbumVisibility::FamilySpace,
            'cover_photo_id' => $privateCover->id]);
        $privateCoverStory = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $owner->id,
            'album_id' => $privateAlbum->id, 'body' => $this->document('Story'), 'body_plain_text' => 'Story']);
        $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/stories/{$privateCoverStory->id}")
            ->assertOk()->assertJsonPath('data.hero', null);

        $this->assertFalse(Schema::hasColumn('stories', 'hero_photo_id'));
    }

    public function test_comment_author_presentation_queries_remain_bounded(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'story-comment-batching']);
        $author = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $author->id]);
        $story = Story::query()->create(['family_space_id' => $family->id, 'author_id' => $author->id,
            'photo_id' => $photo->id, 'body' => $this->document('Story'), 'body_plain_text' => 'Story']);
        foreach (range(1, 12) as $index) {
            StoryComment::query()->create(['family_space_id' => $family->id, 'story_id' => $story->id,
                'author_id' => $author->id, 'body' => $this->document("Comment {$index}")]);
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($author)->getJson("/api/families/{$family->slug}/stories/{$story->id}")
            ->assertOk()
            ->assertJsonPath('data.author.display_name', $author->name)
            ->assertJsonPath('data.author.person_id', null);

        $this->assertLessThanOrEqual(30, $queries, 'Story comment presentation queries must remain bounded.');
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

final class StoryReadModelUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        return new MediaDeliveryAuthorization(
            'https://storage.test/'.rawurlencode($key),
            CarbonImmutable::instance($expiresAt),
        );
    }
}
