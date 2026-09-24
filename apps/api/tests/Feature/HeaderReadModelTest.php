<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\FamilyEvent;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\LoveNotificationGroup;
use App\Models\LoveNotificationGroupActor;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonRelationship;
use App\Models\Photo;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HeaderReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new HeaderReadModelUrlSigner);
    }

    public function test_notification_rows_derive_typed_actor_target_and_excerpt_without_changing_state(): void
    {
        [$family, $recipient, $actor] = $this->family('notification-presentation');
        $actorPerson = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Sarah Mercer',
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $actorPerson->id,
            'user_id' => $actor->id,
            'created_by' => $recipient->id,
        ]);
        $storyPhoto = $this->photo($family, $actor, PhotoVisibility::FamilySpace, true);
        $storyPhoto->photoPeople()->create([
            'family_space_id' => $family->id,
            'person_id' => $actorPerson->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $actor->id,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);
        $story = Story::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $actorPerson->id,
            'photo_id' => $storyPhoto->id,
            'author_id' => $recipient->id,
            'body' => $this->document('The day at Blackpool'),
            'body_plain_text' => 'The day at Blackpool',
        ]);
        $comment = StoryComment::query()->create([
            'family_space_id' => $family->id,
            'story_id' => $story->id,
            'author_id' => $actor->id,
            'body' => $this->document('I remember that day!'),
        ]);
        $commentNotice = FamilyNotification::query()->create([
            'family_space_id' => $family->id,
            'recipient_user_id' => $recipient->id,
            'category' => 'comment',
            'source_action_id' => $comment->id,
            'story_id' => $story->id,
        ]);
        $loveGroup = LoveNotificationGroup::query()->create([
            'family_space_id' => $family->id,
            'story_id' => $story->id,
        ]);
        LoveNotificationGroupActor::query()->create([
            'family_space_id' => $family->id,
            'group_id' => $loveGroup->id,
            'actor_user_id' => $actor->id,
        ]);
        $loveNotice = FamilyNotification::query()->create([
            'family_space_id' => $family->id,
            'recipient_user_id' => $recipient->id,
            'category' => 'love',
            'source_action_id' => $loveGroup->id,
            'story_id' => $story->id,
        ]);
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $recipient->id,
            'name' => 'Family reunion',
        ]);
        $attendanceNotice = FamilyNotification::query()->create([
            'family_space_id' => $family->id,
            'recipient_user_id' => $recipient->id,
            'category' => 'attendance',
            'source_action_id' => (string) Str::ulid(),
            'event_id' => $event->id,
        ]);

        $response = $this->actingAs($recipient)
            ->getJson("/api/families/{$family->slug}/notifications")
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $rows = $response->collect('data')->keyBy('id');
        $commentRow = $rows->get($commentNotice->id);
        $this->assertSame('Sarah Mercer', $commentRow['presentation']['actor']['display_name']);
        $this->assertSame('SM', $commentRow['presentation']['actor']['initials']);
        $this->assertSame($actorPerson->id, $commentRow['presentation']['actor']['person_id']);
        $this->assertStringContainsString('thumbnail.v1.webp', $commentRow['presentation']['actor']['portrait_thumbnail_url']);
        $this->assertSame('Sarah Mercer commented on your story', $commentRow['presentation']['headline']);
        $this->assertSame('I remember that day!', $commentRow['presentation']['detail']);
        $this->assertSame(['type' => 'story', 'id' => $story->id], $commentRow['presentation']['target']);
        $this->assertStringContainsString('thumbnail.v1.webp', $commentRow['presentation']['thumbnail_url']);
        $this->assertNull($commentRow['read_at']);
        $this->assertNotEmpty($commentRow['created_at']);
        $this->assertSame('Sarah Mercer loved your Story', $rows->get($loveNotice->id)['presentation']['headline']);
        $attendance = $rows->get($attendanceNotice->id);
        $this->assertNull($attendance['presentation']['actor']);
        $this->assertSame('Someone responded to an Event invitation', $attendance['presentation']['headline']);
        $this->assertSame('Family reunion', $attendance['presentation']['target_label']);
    }

    public function test_notification_actor_portrait_does_not_leak_an_inaccessible_photo(): void
    {
        [$family, $recipient, $actor] = $this->family('notification-private-portrait');
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $actor->id,
            'created_by' => $recipient->id,
        ]);
        $privatePhoto = $this->photo($family, $actor, PhotoVisibility::Private);
        $privatePhoto->photoPeople()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $actor->id,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);
        $story = Story::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'author_id' => $actor->id,
            'body' => $this->document('Shared story'),
            'body_plain_text' => 'Shared story',
        ]);
        $notice = FamilyNotification::query()->create([
            'family_space_id' => $family->id,
            'recipient_user_id' => $recipient->id,
            'category' => 'story',
            'source_action_id' => $story->id,
            'story_id' => $story->id,
        ]);

        $this->actingAs($recipient)->getJson("/api/families/{$family->slug}/notifications")
            ->assertOk()
            ->assertJsonPath('data.0.id', $notice->id)
            ->assertJsonPath('data.0.presentation.actor.person_id', $person->id)
            ->assertJsonPath('data.0.presentation.actor.portrait_thumbnail_url', null)
            ->assertJsonPath('data.0.presentation.thumbnail_url', null);
    }

    public function test_search_adds_only_authorized_person_and_album_presentation_fields(): void
    {
        [$family, $owner, $viewer] = $this->family('header-search');
        $viewerPerson = Person::factory()->create(['family_space_id' => $family->id]);
        $resultPerson = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'William Mercer',
        ]);
        $fallbackPerson = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Willow Mercer',
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $viewerPerson->id,
            'user_id' => $viewer->id,
            'created_by' => $owner->id,
        ]);
        PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $resultPerson->id,
            'related_person_id' => $viewerPerson->id,
            'type' => 'sibling_of',
            'status' => 'confirmed',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        $cover = $this->photo($family, $owner, PhotoVisibility::FamilySpace, true);
        $cover->photoPeople()->create([
            'family_space_id' => $family->id,
            'person_id' => $resultPerson->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);
        $second = $this->photo($family, $owner, PhotoVisibility::FamilySpace);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'William album',
            'visibility' => AlbumVisibility::FamilySpace,
            'cover_photo_id' => $cover->id,
            'cover_focal_x' => 0.5,
            'cover_focal_y' => 0.5,
        ]);
        foreach ([1 => $cover, 2 => $second] as $position => $photo) {
            AlbumPhoto::query()->create([
                'family_space_id' => $family->id,
                'album_id' => $album->id,
                'photo_id' => $photo->id,
                'position' => $position,
                'added_by' => $owner->id,
            ]);
        }

        $response = $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/search?q=william")
            ->assertOk()
            ->assertJsonPath('data.people.items.0.id', $resultPerson->id)
            ->assertJsonPath('data.people.items.0.relationship_to_viewer', 'your sibling')
            ->assertJsonPath('data.albums.items.0.id', $album->id)
            ->assertJsonPath('data.albums.items.0.photo_count', 2);
        $this->assertStringContainsString('thumbnail.v1.webp', $response->json('data.people.items.0.portrait_thumbnail_url'));
        $this->assertStringContainsString('thumbnail.v1.webp', $response->json('data.albums.items.0.cover_thumbnail_url'));

        $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/search?q=willow")
            ->assertOk()
            ->assertJsonPath('data.people.items.0.id', $fallbackPerson->id)
            ->assertJsonPath('data.people.items.0.relationship_to_viewer', null)
            ->assertJsonPath('data.people.items.0.portrait_thumbnail_url', null);
    }

    public function test_family_response_exposes_only_the_current_users_link_in_that_family(): void
    {
        [$first, $owner, $viewer] = $this->family('first-link-family');
        [$second] = $this->family('second-link-family', $owner, $viewer);
        $firstPerson = Person::factory()->create(['family_space_id' => $first->id]);
        $secondPerson = Person::factory()->create(['family_space_id' => $second->id]);
        PersonAccountLink::query()->create([
            'family_space_id' => $first->id,
            'person_id' => $firstPerson->id,
            'user_id' => $viewer->id,
            'created_by' => $owner->id,
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $second->id,
            'person_id' => $secondPerson->id,
            'user_id' => $viewer->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/families/{$first->slug}")
            ->assertOk()->assertJsonPath('data.current_user_person_id', $firstPerson->id);
        $this->actingAs($viewer)->getJson("/api/families/{$second->slug}")
            ->assertOk()->assertJsonPath('data.current_user_person_id', $secondPerson->id);
        $this->actingAs($owner)->getJson("/api/families/{$first->slug}")
            ->assertOk()->assertJsonPath('data.current_user_person_id', null);
    }

    /** @return array{FamilySpace, User, User} */
    private function family(string $slug, ?User $owner = null, ?User $member = null): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $owner ??= User::factory()->create();
        $member ??= User::factory()->create(['name' => 'Sarah Mercer']);
        foreach ([[$owner, FamilySpaceRole::Owner], [$member, FamilySpaceRole::Member]] as [$user, $role]) {
            FamilySpaceMembership::query()->create([
                'family_space_id' => $family->id,
                'user_id' => $user->id,
                'role' => $role,
                'state' => MembershipState::Active,
                'accepted_at' => now(),
            ]);
        }

        return [$family, $owner, $member];
    }

    private function photo(
        FamilySpace $family,
        User $creator,
        PhotoVisibility $visibility,
        bool $withThumbnail = false,
    ): Photo {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
            'state' => $withThumbnail ? MediaUploadState::Ready : MediaUploadState::Initiated,
            'canonical_object_key' => $withThumbnail ? "families/{$family->id}/media/canonical.jpg" : null,
            'canonical_mime_type' => $withThumbnail ? 'image/jpeg' : null,
        ]);
        if ($withThumbnail) {
            MediaVariant::query()->create([
                'family_space_id' => $family->id,
                'media_upload_id' => $upload->id,
                'transform_name' => MediaVariantTransform::Thumbnail,
                'processing_version' => (int) config('media.processing.variant_processing_version'),
                'object_key' => "families/{$family->id}/media/{$upload->id}/variants/thumbnail.v1.webp",
                'mime_type' => 'image/webp',
                'sha256' => hash('sha256', 'thumbnail'),
                'pixel_width' => 320,
                'pixel_height' => 320,
                'byte_size' => 100,
            ]);
        }

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
        ]);
    }

    /** @return array{schema_version: int, blocks: list<array<string, mixed>>} */
    private function document(string $text): array
    {
        return ['schema_version' => 1, 'blocks' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ]]];
    }
}

final class HeaderReadModelUrlSigner implements MediaDeliveryUrlSigner
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
