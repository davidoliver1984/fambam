<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\NotificationCategory;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonMerge;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoPerson;
use App\Models\Story;
use App\Models\User;
use App\Services\NotificationManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExtendedRichTextSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_biography_album_and_event_descriptions_store_documents_plain_text_and_mentions(): void
    {
        [$family, $owner] = $this->family('extended-rich-text');
        $mentioned = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Ada Mercer',
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family gathering']);
        $document = $this->document($mentioned);

        $personResponse = $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/people/{$person->id}", [
            'biography' => $document,
        ])->assertOk()->assertJsonPath('data.biography', 'Remembering Ada Mercer');
        $albumResponse = $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/albums/{$album->id}", [
            'description' => $document,
        ])->assertOk()->assertJsonPath('data.description', 'Remembering Ada Mercer');
        $eventResponse = $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/events/{$event->id}", [
            'description' => $document,
        ])->assertOk()->assertJsonPath('data.description', 'Remembering Ada Mercer');

        foreach ([
            ['person_biography_mentions', 'biography_person_id', $person->id],
            ['album_description_mentions', 'album_id', $album->id],
            ['event_description_mentions', 'event_id', $event->id],
        ] as [$table, $ownerColumn, $ownerId]) {
            $this->assertDatabaseHas($table, [$ownerColumn => $ownerId, 'person_id' => $mentioned->id]);
        }
        $this->assertSame(1, $personResponse->json('data.biography_document.schema_version'));
        $this->assertSame(1, $albumResponse->json('data.description_document.schema_version'));
        $this->assertSame(1, $eventResponse->json('data.description_document.schema_version'));
    }

    public function test_photo_comment_mentions_are_transactional_and_revisions_keep_structured_bodies(): void
    {
        [$family, $owner] = $this->family('photo-comment-rich-text');
        $mentioned = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Ada Mercer']);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";

        $created = $this->actingAs($owner)->postJson("{$base}/comments", [
            'album_id' => $album->id,
            'body' => $this->document($mentioned),
        ])->assertCreated()->assertJsonPath('data.body', 'Remembering Ada Mercer');
        $commentId = $created->json('data.id');
        $mentionId = $created->json('data.body_document.blocks.0.content.1.mention_id');
        $this->assertDatabaseHas('photo_comment_person_mentions', [
            'photo_comment_id' => $commentId, 'mention_id' => $mentionId, 'person_id' => $mentioned->id,
        ]);

        $updated = $created->json('data.body_document');
        $replacement = Person::factory()->create(['family_space_id' => $family->id]);
        $this->getConnection()->table('photo_comment_person_mentions')->where('mention_id', $mentionId)
            ->update(['person_id' => $replacement->id]);
        $updated['blocks'][0]['content'][0]['text'] = 'Still remembering ';
        $response = $this->actingAs($owner)->patchJson("{$base}/comments/{$commentId}", ['body' => $updated])
            ->assertOk()->assertJsonPath('data.body', 'Still remembering Ada Mercer');
        $this->assertSame($mentioned->id, $response->json('data.body_document.blocks.0.content.1.person_id'));
        $this->assertSame('Ada Mercer', $response->json('data.body_document.blocks.0.content.1.label'));
        $this->assertDatabaseHas('photo_comment_person_mentions', ['mention_id' => $mentionId]);
        $revision = PhotoComment::findOrFail($commentId)->revisions()->firstOrFail();
        $this->assertSame('Remembering ', $revision->body['blocks'][0]['content'][0]['text']);
    }

    public function test_photo_comment_mentions_are_explicit_notification_recipients(): void
    {
        [$family, $owner] = $this->family('photo-comment-mention-notification');
        $recipient = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $recipient->id, 'role' => FamilySpaceRole::Member]);
        $mentioned = Person::factory()->create(['family_space_id' => $family->id]);
        PersonAccountLink::query()->create(['family_space_id' => $family->id, 'person_id' => $mentioned->id,
            'user_id' => $recipient->id, 'created_by' => $owner->id]);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);

        $commentId = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/photos/{$photo->id}/comments", [
            'album_id' => $album->id, 'body' => $this->document($mentioned),
        ])->assertCreated()->json('data.id');
        app(NotificationManager::class)->process(
            TenantOperationContext::forBackground($family->id, $owner->id)->toArray(),
            NotificationCategory::Comment,
            $commentId,
            ['photo_id' => $photo->id, 'album_id' => $album->id, 'comment_id' => $commentId],
        );

        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $recipient->id, 'category' => NotificationCategory::Comment->value,
            'comment_id' => $commentId,
        ]);
    }

    public function test_person_merge_and_guarded_reversal_restore_every_extended_mention_from_the_operation_snapshot(): void
    {
        [$family, $owner] = $this->family('extended-mention-merge');
        $survivor = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Survivor']);
        $absorbed = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Absorbed']);
        $biographyOwner = Person::factory()->create(['family_space_id' => $family->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family gathering']);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);
        $document = $this->document($absorbed);
        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/people/{$biographyOwner->id}", ['biography' => $document])->assertOk();
        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/albums/{$album->id}", ['description' => $document])->assertOk();
        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/events/{$event->id}", ['description' => $document])->assertOk();
        $this->actingAs($owner)->postJson("/api/families/{$family->slug}/photos/{$photo->id}/comments", [
            'album_id' => $album->id, 'body' => $document,
        ])->assertCreated();

        $mergeId = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/people/{$absorbed->id}/merge", [
            'survivor_person_id' => $survivor->id,
        ])->assertCreated()->json('data.id');
        foreach (['person_biography_mentions', 'album_description_mentions',
            'event_description_mentions', 'photo_comment_person_mentions'] as $table) {
            $this->assertDatabaseMissing($table, ['person_id' => $absorbed->id]);
            $this->assertDatabaseHas($table, ['person_id' => $survivor->id]);
            $this->assertCount(1, PersonMerge::findOrFail($mergeId)->provenance['before'][$table]);
        }

        $this->actingAs($owner)->postJson("/api/families/{$family->slug}/person-merges/{$mergeId}/reverse")
            ->assertOk();
        foreach (['person_biography_mentions', 'album_description_mentions',
            'event_description_mentions', 'photo_comment_person_mentions'] as $table) {
            $this->assertDatabaseHas($table, ['person_id' => $absorbed->id]);
        }
    }

    public function test_contributor_can_mention_only_people_confirmed_on_photos_they_can_see(): void
    {
        [$family, $owner] = $this->family('scoped-comment-mentions');
        $contributor = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $contributor->id, 'role' => FamilySpaceRole::Contributor]);
        $visiblePhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => 'private']);
        $hiddenPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => 'private']);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Selected album', 'visibility' => AlbumVisibility::Selected]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);
        $album->photos()->attach($visiblePhoto->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);
        $visiblePerson = Person::factory()->create(['family_space_id' => $family->id]);
        $hiddenPerson = Person::factory()->create(['family_space_id' => $family->id]);
        foreach ([[$visiblePhoto, $visiblePerson], [$hiddenPhoto, $hiddenPerson]] as [$photo, $person]) {
            PhotoPerson::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id,
                'person_id' => $person->id, 'status' => 'approved', 'created_by' => $owner->id,
                'resolved_by' => $owner->id, 'resolved_at' => now()]);
        }
        $path = "/api/families/{$family->slug}/photos/{$visiblePhoto->id}/comments";

        $this->actingAs($contributor)->postJson($path, [
            'album_id' => $album->id, 'body' => $this->document($hiddenPerson),
        ])->assertUnprocessable();
        $this->actingAs($contributor)->postJson($path, [
            'album_id' => $album->id, 'body' => $this->document($visiblePerson),
        ])->assertCreated();
    }

    public function test_every_rich_text_read_surface_resolves_merged_mentions_without_rewriting_history(): void
    {
        [$family, $owner] = $this->family('resolved-rich-text-reads');
        $absorbed = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Original Person']);
        $survivor = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Current Person']);
        $biographyOwner = Person::factory()->create(['family_space_id' => $family->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family gathering']);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);
        $document = $this->document($absorbed);
        $storyId = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/stories", [
            'subject_type' => 'photo', 'subject_id' => $photo->id, 'body' => $document,
        ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/families/{$family->slug}/stories/{$storyId}/comments", ['body' => $document])->assertCreated();
        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/people/{$biographyOwner->id}", ['biography' => $document])->assertOk();
        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/albums/{$album->id}", ['description' => $document])->assertOk();
        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/events/{$event->id}", ['description' => $document])->assertOk();
        $this->actingAs($owner)->postJson("/api/families/{$family->slug}/photos/{$photo->id}/comments", [
            'album_id' => $album->id, 'body' => $document,
        ])->assertCreated();

        $this->actingAs($owner)->postJson("/api/families/{$family->slug}/people/{$absorbed->id}/merge", [
            'survivor_person_id' => $survivor->id,
        ])->assertCreated();
        $responses = [
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/stories/{$storyId}")->assertOk()->json('data.body_html'),
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/stories/{$storyId}")->assertOk()->json('data.comments.0.body_html'),
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/people/{$biographyOwner->id}")->assertOk()->json('data.biography_html'),
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/albums/{$album->id}")->assertOk()->json('data.description_html'),
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/events/{$event->id}")->assertOk()->json('data.description_html'),
            $this->actingAs($owner)->getJson("/api/families/{$family->slug}/photos/{$photo->id}/conversation?album_id={$album->id}")->assertOk()->json('data.comments.0.body_html'),
        ];
        foreach ($responses as $html) {
            $this->assertStringContainsString("/people/{$survivor->id}", $html);
            $this->assertStringContainsString('Current Person', $html);
            $this->assertStringNotContainsString("/people/{$absorbed->id}", $html);
        }
        $this->assertSame($absorbed->id, Story::findOrFail($storyId)->body['blocks'][0]['content'][1]['person_id']);
    }

    public function test_rich_text_presentation_resolves_each_mention_only_when_the_viewer_may_see_that_person(): void
    {
        [$family, $owner] = $this->family('authorized-rich-text-rendering');
        $contributor = User::factory()->create();
        $contributorMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $contributor->id, 'role' => FamilySpaceRole::Contributor,
        ]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Selected album', 'visibility' => AlbumVisibility::Selected]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $contributorMembership->id, 'can_view' => true,
            'can_contribute' => true, 'granted_by' => $owner->id]);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => 'private']);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);
        $visible = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Visible Live Name']);
        $hidden = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Hidden Live Name']);
        PhotoPerson::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id,
            'person_id' => $visible->id, 'status' => 'approved', 'created_by' => $owner->id,
            'resolved_by' => $owner->id, 'resolved_at' => now()]);
        $document = ['schema_version' => 1, 'blocks' => [[
            'type' => 'paragraph', 'content' => [
                ['type' => 'mention', 'person_id' => $visible->id, 'label' => 'Visible historical label'],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'mention', 'person_id' => $hidden->id, 'label' => 'Hidden historical label'],
            ],
        ]]];
        $storyId = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/stories", [
            'subject_type' => 'album', 'subject_id' => $album->id, 'body' => $document,
        ])->assertCreated()->json('data.id');

        $contributorResponse = $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/stories/{$storyId}");
        $this->assertSame(200, $contributorResponse->status(), $contributorResponse->content());
        $contributorHtml = $contributorResponse->json('data.body_html');
        $this->assertStringContainsString("href=\"/families/{$family->slug}/people/{$visible->id}\"", $contributorHtml);
        $this->assertStringContainsString('Visible Live Name', $contributorHtml);
        $this->assertStringContainsString('Hidden historical label', $contributorHtml);
        $this->assertStringNotContainsString($hidden->id, $contributorHtml);
        $this->assertStringNotContainsString('Hidden Live Name', $contributorHtml);

        $ownerHtml = $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/stories/{$storyId}")->assertOk()->json('data.body_html');
        $this->assertStringContainsString("href=\"/families/{$family->slug}/people/{$visible->id}\"", $ownerHtml);
        $this->assertStringContainsString("href=\"/families/{$family->slug}/people/{$hidden->id}\"", $ownerHtml);
        $this->assertStringContainsString('Hidden Live Name', $ownerHtml);
    }

    /** @return array{FamilySpace, User} */
    private function family(string $slug): array
    {
        config(['filesystems.disks.s3.region' => 'us-east-1']);
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $owner->id, 'role' => FamilySpaceRole::Owner]);

        return [$family, $owner];
    }

    /** @return array<string, mixed> */
    private function document(Person $person): array
    {
        return ['schema_version' => 1, 'blocks' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Remembering '],
                ['type' => 'mention', 'person_id' => $person->id, 'label' => $person->preferred_name],
            ],
        ]]];
    }
}
