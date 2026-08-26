<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Enums\PersonProposalStatus;
use App\Enums\PhotoVisibility;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new PhotoTestMediaDeliveryUrlSigner);
    }

    public function test_promotable_uploads_are_discoverable_only_with_existing_photo_creation_authority(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'promotable-uploads');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $contributor = $this->addMember($family, FamilySpaceRole::Contributor);
        $ownerUpload = $this->readyUpload($family, $owner);
        $memberUpload = $this->readyUpload($family, $member);
        $promotedUpload = $this->readyUpload($family, $member);
        Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $promotedUpload->id,
            'created_by' => $member->id,
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Contribution album',
            'visibility' => 'selected',
        ]);
        $albumUpload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'state' => MediaUploadState::Ready,
            'target_album_id' => $album->id,
        ]);
        $processingUpload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'state' => MediaUploadState::Processing,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/families/promotable-uploads/photos/promotable-uploads')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $ownerUpload->id])
            ->assertJsonFragment(['id' => $memberUpload->id])
            ->assertJsonMissing(['id' => $promotedUpload->id])
            ->assertJsonMissing(['id' => $albumUpload->id])
            ->assertJsonMissing(['id' => $processingUpload->id]);

        $this->actingAs($member)
            ->getJson('/api/families/promotable-uploads/photos/promotable-uploads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $memberUpload->id);

        $this->actingAs($contributor)
            ->getJson('/api/families/promotable-uploads/photos/promotable-uploads')
            ->assertForbidden();
    }

    public function test_member_creates_a_photo_only_from_their_own_ready_upload_and_defaults_to_family_space(): void
    {
        [$family, $member] = $this->familyWithRole(FamilySpaceRole::Member, 'photo-creation');
        $ownUpload = $this->readyUpload($family, $member);
        $otherMember = $this->addMember($family, FamilySpaceRole::Member);
        $otherUpload = $this->readyUpload($family, $otherMember);

        $response = $this->actingAs($member)
            ->postJson('/api/families/photo-creation/photos', [
                'media_upload_id' => $ownUpload->id,
                'caption' => 'Nan at the seaside',
                'archive_source_description' => 'Green family album',
                'tags' => ['Holiday', '  Seaside  ', 'holiday'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'photo_created')
            ->assertJsonPath('data.photo.visibility', PhotoVisibility::FamilySpace->value)
            ->assertJsonPath('data.photo.media_upload.id', $ownUpload->id)
            ->assertJsonPath('data.photo.tags.0.label', 'Holiday')
            ->assertJsonPath('data.photo.tags.1.label', 'Seaside');

        $photo = Photo::query()->findOrFail($response->json('data.photo.id'));
        $this->assertSame($member->id, $photo->created_by);
        $this->assertSame('Green family album', $photo->archive_source_description);
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $family->id,
            'actor_user_id' => $member->id,
            'action' => 'photo.created',
            'subject_id' => $photo->id,
        ]);

        $this->actingAs($member)
            ->postJson('/api/families/photo-creation/photos', ['media_upload_id' => $otherUpload->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_upload_id');
        $this->actingAs($member)
            ->postJson('/api/families/photo-creation/photos', ['media_upload_id' => $ownUpload->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_upload_id');
    }

    public function test_owner_can_promote_another_members_ready_upload_but_not_an_unready_upload(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'owner-promotion');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $ready = $this->readyUpload($family, $member);
        $unready = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'state' => MediaUploadState::Processing,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/families/owner-promotion/photos', [
                'media_upload_id' => $ready->id,
                'visibility' => 'private',
            ])
            ->assertCreated()
            ->assertJsonPath('data.photo.created_by', $owner->id)
            ->assertJsonPath('data.photo.visibility', 'private');

        $this->actingAs($owner)
            ->postJson('/api/families/owner-promotion/photos', ['media_upload_id' => $unready->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_upload_id');
    }

    public function test_private_photo_is_hidden_and_cannot_be_reached_through_any_phase_five_delivery_endpoint(): void
    {
        [$family, $creator] = $this->familyWithRole(FamilySpaceRole::Member, 'private-photo');
        $otherMember = $this->addMember($family, FamilySpaceRole::Member);
        $administrator = $this->addMember($family, FamilySpaceRole::Administrator);
        $upload = $this->readyUpload($family, $creator);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => PhotoVisibility::Private,
        ]);
        MediaVariant::query()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'transform_name' => MediaVariantTransform::Thumbnail,
            'processing_version' => 1,
            'object_key' => "families/{$family->id}/media/{$upload->id}/variants/thumbnail.v1.webp",
            'mime_type' => 'image/webp',
            'sha256' => hash('sha256', 'thumbnail'),
            'pixel_width' => 320,
            'pixel_height' => 320,
            'byte_size' => 100,
        ]);
        $base = "/api/families/private-photo/media-uploads/{$upload->id}";

        $this->actingAs($otherMember)->getJson('/api/families/private-photo/photos')->assertJsonCount(0, 'data');
        $this->actingAs($otherMember)->getJson("/api/families/private-photo/photos/{$photo->id}")->assertNotFound();
        $this->actingAs($otherMember)->getJson("{$base}/canonical")->assertForbidden();
        $this->actingAs($otherMember)->getJson("{$base}/variants/thumbnail")->assertForbidden();
        $this->actingAs($otherMember)->getJson("{$base}/original")->assertForbidden();

        foreach ([$creator, $administrator] as $viewer) {
            $this->actingAs($viewer)->getJson("{$base}/canonical")->assertOk();
            $this->actingAs($viewer)->getJson("{$base}/variants/thumbnail")->assertOk();
            $this->actingAs($viewer)->getJson("{$base}/original")->assertOk();
        }
    }

    public function test_member_proposes_provenance_and_owner_confirmation_updates_one_authoritative_claim(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'photo-provenance');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Aunt May']);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $this->readyUpload($family, $member)->id,
            'created_by' => $member->id,
        ]);

        $proposal = $this->actingAs($member)
            ->postJson("/api/families/photo-provenance/photos/{$photo->id}/provenance-proposals", [
                'role' => 'photographer',
                'person_id' => $person->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PersonProposalStatus::Pending->value)
            ->json('data');

        $this->assertNull($photo->refresh()->photographer_person_id);
        $this->actingAs($member)
            ->getJson("/api/families/photo-provenance/photos/{$photo->id}/provenance-proposals")
            ->assertForbidden();
        $this->actingAs($owner)
            ->getJson("/api/families/photo-provenance/photos/{$photo->id}/provenance-proposals")
            ->assertOk()
            ->assertJsonPath('data.0.person.preferred_name', 'Aunt May');
        $this->actingAs($owner)
            ->postJson("/api/families/photo-provenance/photos/{$photo->id}/provenance-proposals/{$proposal['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PersonProposalStatus::Approved->value);

        $photo->refresh();
        $this->assertSame($person->id, $photo->photographer_person_id);
        $this->assertNull($photo->photographer_description);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo.provenance_proposed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo.provenance_confirmed']);
    }

    public function test_authoritative_free_text_claim_is_mutually_exclusive_and_archive_source_is_independent(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'source-separation');
        $upload = $this->readyUpload($family, $owner);
        $photoId = $this->actingAs($owner)
            ->postJson('/api/families/source-separation/photos', [
                'media_upload_id' => $upload->id,
                'archive_source_description' => 'Box labelled Spain',
            ])->assertCreated()->json('data.photo.id');

        $this->actingAs($owner)
            ->postJson("/api/families/source-separation/photos/{$photoId}/provenance-proposals", [
                'role' => 'physical_owner',
                'description' => "Mum's collection",
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PersonProposalStatus::Approved->value);

        $photo = Photo::query()->findOrFail($photoId);
        $this->assertSame('Box labelled Spain', $photo->archive_source_description);
        $this->assertSame("Mum's collection", $photo->physical_source_description);
        $this->assertNull($photo->physical_owner_person_id);

        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $this->actingAs($owner)
            ->postJson("/api/families/source-separation/photos/{$photoId}/provenance-proposals", [
                'role' => 'physical_owner',
                'person_id' => $person->id,
                'description' => 'Both is invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provenance');
    }

    public function test_any_viewing_member_can_replace_tags_but_only_creator_or_administrator_edits_content(): void
    {
        [$family, $creator] = $this->familyWithRole(FamilySpaceRole::Member, 'photo-content');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $administrator = $this->addMember($family, FamilySpaceRole::Administrator);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $this->readyUpload($family, $creator)->id,
            'created_by' => $creator->id,
            'caption' => 'Original',
        ]);

        $this->actingAs($member)
            ->putJson("/api/families/photo-content/photos/{$photo->id}/tags", ['tags' => ['School', 'Family']])
            ->assertOk()
            ->assertJsonCount(2, 'data.tags');
        $this->actingAs($member)
            ->patchJson("/api/families/photo-content/photos/{$photo->id}", ['caption' => 'Not allowed'])
            ->assertForbidden();
        $this->actingAs($creator)
            ->patchJson("/api/families/photo-content/photos/{$photo->id}", ['caption' => 'Creator edit'])
            ->assertOk();
        $this->actingAs($administrator)
            ->patchJson("/api/families/photo-content/photos/{$photo->id}", ['caption' => 'Admin correction'])
            ->assertOk();

        $this->assertSame('Admin correction', $photo->refresh()->caption);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo.tags_changed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo.content_updated']);
    }

    public function test_photo_routes_and_provenance_people_fail_closed_across_tenants(): void
    {
        [$firstFamily, $firstOwner] = $this->familyWithRole(FamilySpaceRole::Owner, 'first-photo-family');
        [$secondFamily, $secondOwner] = $this->familyWithRole(FamilySpaceRole::Owner, 'second-photo-family');
        $photo = Photo::factory()->create([
            'family_space_id' => $secondFamily->id,
            'media_upload_id' => $this->readyUpload($secondFamily, $secondOwner)->id,
            'created_by' => $secondOwner->id,
        ]);
        $foreignPerson = Person::factory()->create(['family_space_id' => $secondFamily->id]);

        $this->actingAs($firstOwner)
            ->getJson("/api/families/first-photo-family/photos/{$photo->id}")
            ->assertNotFound();

        $ownPhoto = Photo::factory()->create([
            'family_space_id' => $firstFamily->id,
            'media_upload_id' => $this->readyUpload($firstFamily, $firstOwner)->id,
            'created_by' => $firstOwner->id,
        ]);
        $this->actingAs($firstOwner)
            ->postJson("/api/families/first-photo-family/photos/{$ownPhoto->id}/provenance-proposals", [
                'role' => 'scanner',
                'person_id' => $foreignPerson->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('person_id');
    }

    /** @return array{FamilySpace, User} */
    private function familyWithRole(FamilySpaceRole $role, string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $user = $this->addMember($family, $role);

        return [$family, $user];
    }

    private function addMember(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function readyUpload(FamilySpace $family, User $uploader): MediaUpload
    {
        $id = (string) Str::ulid();

        return MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $uploader->id,
            'state' => MediaUploadState::Ready,
            'original_object_key' => "families/{$family->id}/media/{$id}/original.jpg",
            'original_sha256' => hash('sha256', 'original-'.$id),
            'detected_mime_type' => 'image/jpeg',
            'canonical_object_key' => "families/{$family->id}/media/{$id}/canonical.jpg",
            'canonical_mime_type' => 'image/jpeg',
            'canonical_sha256' => hash('sha256', 'canonical-'.$id),
        ]);
    }
}

class PhotoTestMediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
    ): MediaDeliveryAuthorization {
        return new MediaDeliveryAuthorization(
            'https://storage.test/'.rawurlencode($key),
            CarbonImmutable::instance($expiresAt),
        );
    }
}
