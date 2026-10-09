<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceDefaultVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\Album;
use App\Models\AuditEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilySettingsCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new FamilySettingsMediaDeliveryUrlSigner);
    }

    public function test_owner_and_administrator_can_update_settings_but_member_and_other_family_cannot(): void
    {
        [$family, $owner] = $this->familyWithOwner('settings-family');
        [$administrator] = $this->addMember($family, FamilySpaceRole::Administrator);
        [$member] = $this->addMember($family, FamilySpaceRole::Member);
        [$otherFamily, $otherOwner] = $this->familyWithOwner('other-settings-family');

        $this->actingAs($owner)->patchJson('/api/families/settings-family', [
            'name' => "  The\n  Oliver   Family ",
            'description' => '  A private archive for everyone.  ',
            'default_visibility' => 'private',
        ])->assertOk()
            ->assertJsonPath('data.name', 'The Oliver Family')
            ->assertJsonPath('data.slug', 'settings-family')
            ->assertJsonPath('data.description', 'A private archive for everyone.')
            ->assertJsonPath('data.default_visibility', 'private')
            ->assertJsonPath('data.permissions.can_transfer_ownership', true);

        $this->actingAs($administrator)->patchJson('/api/families/settings-family', [
            'description' => '   ',
        ])->assertOk()->assertJsonPath('data.description', null);
        $this->actingAs($member)->patchJson('/api/families/settings-family', [
            'name' => 'Forbidden',
        ])->assertForbidden();
        $this->actingAs($member)->getJson('/api/families/settings-family')
            ->assertOk()
            ->assertJsonPath('data.permissions.can_update_family_settings', false)
            ->assertJsonPath('data.permissions.can_manage_members', false)
            ->assertJsonPath('data.permissions.can_leave_family', true);
        $this->actingAs($otherOwner)->patchJson('/api/families/settings-family', [
            'name' => 'Hidden',
        ])->assertNotFound();

        $this->assertSame('settings-family', $family->refresh()->slug);
        foreach ([
            'family_space.name_changed',
            'family_space.description_changed',
            'family_space.default_visibility_changed',
        ] as $action) {
            $this->assertDatabaseHas('audit_events', [
                'family_space_id' => $family->id,
                'action' => $action,
            ]);
        }
        $this->assertSame(FamilySpaceDefaultVisibility::FamilySpace, $otherFamily->default_visibility);
    }

    public function test_settings_validation_and_no_op_update_are_bounded(): void
    {
        [$family, $owner] = $this->familyWithOwner('bounded-settings');
        $this->actingAs($owner)->patchJson('/api/families/bounded-settings', ['name' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($owner)->patchJson('/api/families/bounded-settings', [
            'description' => str_repeat('x', 2001),
            'default_visibility' => 'selected',
        ])->assertUnprocessable()->assertJsonValidationErrors(['description', 'default_visibility']);

        $updatedAt = $family->updated_at;
        $auditCount = AuditEvent::query()->count();
        $this->travel(1)->minute();
        $this->actingAs($owner)->patchJson('/api/families/bounded-settings', [
            'name' => "  {$family->name}  ",
            'description' => null,
            'default_visibility' => 'family_space',
        ])->assertOk();

        $this->assertTrue($family->refresh()->updated_at->equalTo($updatedAt));
        $this->assertSame($auditCount, AuditEvent::query()->count());
    }

    public function test_family_default_applies_only_when_photo_and_album_visibility_is_omitted(): void
    {
        [$family, $owner] = $this->familyWithOwner('privacy-defaults', [
            'default_visibility' => FamilySpaceDefaultVisibility::Private,
        ]);

        $privatePhoto = $this->createPhoto($family, $owner);
        $familyPhoto = $this->createPhoto($family, $owner, PhotoVisibility::FamilySpace);
        $privateAlbumId = $this->actingAs($owner)->postJson('/api/families/privacy-defaults/albums', [
            'name' => 'Private by default',
        ])->assertCreated()->assertJsonPath('data.visibility', 'private')->json('data.id');
        $selectedAlbumId = $this->actingAs($owner)->postJson('/api/families/privacy-defaults/albums', [
            'name' => 'Explicitly selected',
            'visibility' => 'selected',
        ])->assertCreated()->assertJsonPath('data.visibility', 'selected')->json('data.id');

        $this->assertSame(PhotoVisibility::Private, $privatePhoto->visibility);
        $this->assertSame(PhotoVisibility::FamilySpace, $familyPhoto->visibility);
        $this->assertSame(AlbumVisibility::Private, Album::findOrFail($privateAlbumId)->visibility);
        $this->assertSame(AlbumVisibility::Selected, Album::findOrFail($selectedAlbumId)->visibility);

        [$openFamily, $openOwner] = $this->familyWithOwner('family-space-default');
        $openPhoto = $this->createPhoto($openFamily, $openOwner);
        $openAlbumId = $this->actingAs($openOwner)->postJson('/api/families/family-space-default/albums', [
            'name' => 'Open by default',
        ])->assertCreated()->json('data.id');
        $this->assertSame(PhotoVisibility::FamilySpace, $openPhoto->visibility);
        $this->assertSame(AlbumVisibility::FamilySpace, Album::findOrFail($openAlbumId)->visibility);
    }

    public function test_default_setting_introduction_and_changes_do_not_rewrite_existing_visibility(): void
    {
        [$family, $owner] = $this->familyWithOwner('existing-visibility');
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Existing selected album',
            'visibility' => AlbumVisibility::Selected,
        ]);

        $this->actingAs($owner)->patchJson('/api/families/existing-visibility', [
            'default_visibility' => 'private',
        ])->assertOk();

        $this->assertSame(PhotoVisibility::FamilySpace, $photo->refresh()->visibility);
        $this->assertSame(AlbumVisibility::Selected, $album->refresh()->visibility);
    }

    public function test_membership_read_model_has_truthful_joined_at_and_authorised_linked_person(): void
    {
        [$family, $owner] = $this->familyWithOwner('member-presentation');
        $joinedAt = CarbonImmutable::parse('2025-04-03T12:00:00Z');
        [$member, $membership] = $this->addMember($family, FamilySpaceRole::Member, $joinedAt);
        [, $unlinkedMembership] = $this->addMember($family, FamilySpaceRole::Contributor);
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Aunt Ada',
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $member->id,
            'created_by' => $owner->id,
        ]);
        $avatar = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'purpose' => 'account_avatar',
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/member-avatar.jpg",
            'canonical_mime_type' => 'image/jpeg',
        ]);
        $member->forceFill(['avatar_media_upload_id' => $avatar->id])->save();
        [$otherFamily, $otherOwner] = $this->familyWithOwner('other-member-presentation');
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $otherFamily->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $otherPerson = Person::factory()->create([
            'family_space_id' => $otherFamily->id,
            'preferred_name' => 'Other Family Identity',
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $otherFamily->id,
            'person_id' => $otherPerson->id,
            'user_id' => $member->id,
            'created_by' => $otherOwner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/families/member-presentation/memberships')
            ->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $rows = collect($data)->keyBy('id');
        $this->assertSame($joinedAt->toAtomString(), $rows[$membership->id]['joined_at']);
        $this->assertSame($person->id, $rows[$membership->id]['linked_person']['id']);
        $this->assertSame('Aunt Ada', $rows[$membership->id]['linked_person']['display_name']);
        $this->assertNull($rows[$membership->id]['linked_person']['portrait_thumbnail_url']);
        $this->assertSame($avatar->id, $rows[$membership->id]['user']['avatar']['media_upload_id']);
        $this->assertSame('https://media.example.test/member-avatar.jpg', $rows[$membership->id]['user']['avatar']['url']);
        $this->assertNull($rows[$unlinkedMembership->id]['linked_person']);
        $this->assertTrue(collect($rows)->contains('is_current_user', true));
        $response->assertJsonMissing(['id' => $otherPerson->id]);

        $this->actingAs($member)
            ->getJson('/api/families/member-presentation/memberships')
            ->assertForbidden();
        $this->actingAs($otherOwner)
            ->getJson('/api/families/member-presentation/memberships')
            ->assertNotFound();
    }

    public function test_ownership_transfer_is_atomic_and_former_owner_becomes_administrator(): void
    {
        [$family, $owner, $ownerMembership] = $this->familyWithOwner('ownership-transfer');
        [$target, $targetMembership] = $this->addMember($family, FamilySpaceRole::Member);

        $this->actingAs($owner)->postJson('/api/families/ownership-transfer/ownership-transfer', [
            'membership_id' => $targetMembership->id,
        ])->assertOk()
            ->assertJsonPath('data.role', 'administrator')
            ->assertJsonPath('data.permissions.can_transfer_ownership', false)
            ->assertJsonPath('data.permissions.can_leave_family', true);

        $this->assertSame(FamilySpaceRole::Administrator, $ownerMembership->refresh()->role);
        $this->assertSame(FamilySpaceRole::Owner, $targetMembership->refresh()->role);
        $this->assertSame(1, FamilySpaceMembership::query()
            ->where('family_space_id', $family->id)
            ->where('state', MembershipState::Active->value)
            ->where('role', FamilySpaceRole::Owner->value)->count());
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $family->id,
            'actor_user_id' => $owner->id,
            'action' => 'family_space.ownership_transferred',
        ]);

        $this->actingAs($owner)->postJson('/api/families/ownership-transfer/ownership-transfer', [
            'membership_id' => $targetMembership->id,
        ])->assertForbidden();
        $this->actingAs($target)->postJson('/api/families/ownership-transfer/ownership-transfer', [
            'membership_id' => $ownerMembership->id,
        ])->assertOk();
    }

    public function test_transfer_rejects_ineligible_target_and_non_owner(): void
    {
        [$family, $owner] = $this->familyWithOwner('transfer-eligibility');
        [$administrator] = $this->addMember($family, FamilySpaceRole::Administrator);
        [, $removed] = $this->addMember($family, FamilySpaceRole::Member);
        $removed->update(['state' => MembershipState::Removed, 'removed_at' => now()]);

        $this->actingAs($owner)->postJson('/api/families/transfer-eligibility/ownership-transfer', [
            'membership_id' => $removed->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership');
        $this->actingAs($administrator)->postJson('/api/families/transfer-eligibility/ownership-transfer', [
            'membership_id' => $removed->id,
        ])->assertForbidden();
    }

    public function test_non_owner_can_leave_without_deleting_account_person_link_or_content(): void
    {
        [$family, $owner] = $this->familyWithOwner('self-leave');
        [$member, $membership] = $this->addMember($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $link = PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $member->id,
            'created_by' => $owner->id,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $member->id,
        ]);

        $this->actingAs($member)->postJson('/api/families/self-leave/leave')->assertNoContent();

        $membership->refresh();
        $this->assertSame(MembershipState::Removed, $membership->state);
        $this->assertSame($member->id, $membership->removed_by);
        $this->assertNotNull(User::find($member->id));
        $this->assertNotNull(Person::find($person->id));
        $this->assertNotNull(PersonAccountLink::find($link->id));
        $this->assertNotNull(Photo::find($photo->id));
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $family->id,
            'actor_user_id' => $member->id,
            'action' => 'family_space.member_left',
        ]);
    }

    public function test_owner_must_transfer_before_leaving_then_may_leave_as_administrator(): void
    {
        [$family, $owner, $ownerMembership] = $this->familyWithOwner('transfer-then-leave');
        [, $targetMembership] = $this->addMember($family, FamilySpaceRole::Member);

        $this->actingAs($owner)->postJson('/api/families/transfer-then-leave/leave')
            ->assertUnprocessable()->assertJsonValidationErrors('membership');
        $this->assertSame(MembershipState::Active, $ownerMembership->refresh()->state);
        $this->actingAs($owner)->postJson('/api/families/transfer-then-leave/ownership-transfer', [
            'membership_id' => $targetMembership->id,
        ])->assertOk();
        $this->actingAs($owner)->postJson('/api/families/transfer-then-leave/leave')->assertNoContent();

        $this->assertSame(MembershipState::Removed, $ownerMembership->refresh()->state);
        $this->assertSame(FamilySpaceRole::Owner, $targetMembership->refresh()->role);
    }

    /** @param array<string, mixed> $attributes
     * @return array{FamilySpace, User, FamilySpaceMembership}
     */
    private function familyWithOwner(string $slug, array $attributes = []): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug, ...$attributes]);
        $owner = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);

        return [$family, $owner, $membership];
    }

    /** @return array{User, FamilySpaceMembership} */
    private function addMember(
        FamilySpace $family,
        FamilySpaceRole $role,
        ?DateTimeInterface $joinedAt = null,
    ): array {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => $joinedAt ?? now(),
        ]);

        return [$user, $membership];
    }

    private function createPhoto(
        FamilySpace $family,
        User $actor,
        ?PhotoVisibility $visibility = null,
    ): Photo {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $actor->id,
            'purpose' => 'archive',
            'state' => MediaUploadState::Ready,
        ]);
        $input = ['media_upload_id' => $upload->id];
        if ($visibility !== null) {
            $input['visibility'] = $visibility->value;
        }
        $id = $this->actingAs($actor)->postJson("/api/families/{$family->slug}/photos", $input)
            ->assertCreated()->json('data.photo.id');

        return Photo::findOrFail($id);
    }
}

final class FamilySettingsMediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        return new MediaDeliveryAuthorization(
            'https://media.example.test/'.basename($key),
            CarbonImmutable::instance($expiresAt),
        );
    }
}
