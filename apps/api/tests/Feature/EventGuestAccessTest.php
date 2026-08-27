<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\GuestParticipation;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\Album;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\InvitationIssued;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventGuestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new EventGuestMediaDeliveryUrlSigner);
    }

    public function test_guest_access_is_event_scoped_and_does_not_enable_family_enumeration(): void
    {
        [$family, $owner] = $this->family('guest-boundary');
        [$guest, $membership] = $this->membership($family, FamilySpaceRole::Guest);
        $admitted = $this->event($family, $owner, 'Wedding');
        $other = $this->event($family, $owner, 'Birthday');
        $visibleAlbum = $this->album($family, $owner, $admitted, GuestParticipation::View);
        $otherAlbum = $this->album($family, $owner, $other, GuestParticipation::View);
        $ordinaryAlbum = $this->album($family, $owner, null, GuestParticipation::None);
        $visiblePhoto = $this->photo($family, $owner, $visibleAlbum);
        $otherPhoto = $this->photo($family, $owner, $otherAlbum);
        $ordinaryPhoto = $this->photo($family, $owner, $ordinaryAlbum);
        $this->admit($family, $admitted, $membership);

        $base = "/api/families/{$family->slug}";
        $this->actingAs($guest)->getJson($base)->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/memberships")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/people")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/circles")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/invitations")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/events")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/albums")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/photos")->assertForbidden();

        $this->actingAs($guest)->getJson("{$base}/events/{$admitted->id}")
            ->assertOk()->assertJsonPath('data.name', 'Wedding')
            ->assertJsonCount(1, 'data.albums')->assertJsonCount(0, 'data.attendees');
        $this->actingAs($guest)->getJson("{$base}/albums/{$visibleAlbum->id}")->assertOk();
        $this->actingAs($guest)->getJson("{$base}/photos/{$visiblePhoto->id}")->assertOk();
        $this->actingAs($guest)->getJson("{$base}/media-uploads/{$visiblePhoto->media_upload_id}/canonical")
            ->assertOk();
        $this->actingAs($guest)->getJson("{$base}/media-uploads/{$visiblePhoto->media_upload_id}/original")
            ->assertOk();
        $this->actingAs($guest)->postJson("{$base}/photos/{$visiblePhoto->id}/comments", [
            'body' => 'Lovely day', 'album_id' => $visibleAlbum->id,
        ])
            ->assertCreated();
        $this->actingAs($guest)->putJson("{$base}/photos/{$visiblePhoto->id}/reaction", [
            'reaction' => 'love', 'album_id' => $visibleAlbum->id,
        ])
            ->assertOk();
        $this->actingAs($guest)->postJson("{$base}/photos/{$visiblePhoto->id}/stories", ['body' => 'Not trusted narrative'])
            ->assertForbidden();

        $this->actingAs($guest)->getJson("{$base}/events/{$other->id}")->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/albums/{$otherAlbum->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("{$base}/albums/{$ordinaryAlbum->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("{$base}/photos/{$otherPhoto->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("{$base}/photos/{$ordinaryPhoto->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("{$base}/media-uploads/{$otherPhoto->media_upload_id}/canonical")
            ->assertForbidden();
    }

    public function test_admission_expiry_revocation_and_event_deletion_are_evaluated_live(): void
    {
        config(['events.admission_lifetime_days' => 30]);
        [$family, $owner] = $this->family('guest-lifecycle');
        [$guest, $membership] = $this->membership($family, FamilySpaceRole::Guest);
        $event = $this->event($family, $owner, 'Anniversary');
        $album = $this->album($family, $owner, $event, GuestParticipation::View);
        $admission = $this->admit($family, $event, $membership);
        $url = "/api/families/{$family->slug}/events/{$event->id}";

        $this->actingAs($guest)->getJson($url)->assertOk();
        $admission->update(['admitted_at' => now()->subDays(30)]);
        $this->actingAs($guest)->getJson($url)->assertForbidden();

        $this->actingAs($owner)->postJson("{$url}/admissions", ['membership_id' => $membership->id])
            ->assertCreated();
        $this->actingAs($guest)->getJson($url)->assertOk();
        $this->actingAs($owner)->deleteJson("{$url}/admissions/{$membership->id}")->assertOk();
        $this->actingAs($owner)->deleteJson("{$url}/admissions/{$membership->id}")->assertOk();
        $this->actingAs($guest)->getJson($url)->assertForbidden();

        $this->actingAs($owner)->postJson("{$url}/admissions", ['membership_id' => $membership->id])
            ->assertCreated();
        $this->actingAs($owner)->deleteJson($url)->assertNoContent();
        $this->actingAs($guest)->getJson($url)->assertNotFound();
        $this->actingAs($owner)->postJson("{$url}/restore")->assertOk();
        $this->actingAs($guest)->getJson($url)->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'event.admission_revoked']);
        $this->assertDatabaseHas('audit_events', ['action' => 'event.removed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'event.restored']);
        $this->assertSame($album->id, Album::query()->findOrFail($album->id)->id);
    }

    public function test_guest_creator_authority_never_outlives_live_event_access(): void
    {
        config(['events.admission_lifetime_days' => 30]);
        [$family, $owner] = $this->family('guest-created-photo-lifecycle');
        [$guest, $membership] = $this->membership($family, FamilySpaceRole::Guest);
        $event = $this->event($family, $owner, 'Reception');
        $album = $this->album($family, $owner, $event, GuestParticipation::Contribute);
        $photo = $this->photo($family, $guest, $album);
        $admission = $this->admit($family, $event, $membership);
        $base = "/api/families/{$family->slug}";

        $this->actingAs($guest)->getJson("{$base}/photos/{$photo->id}")->assertOk();
        $this->actingAs($guest)->getJson("{$base}/media-uploads/{$photo->media_upload_id}/canonical")
            ->assertOk();

        $admission->update(['revoked_at' => now(), 'revoked_by' => $owner->id]);
        $this->assertGuestPhotoAccessDenied($guest, $base, $photo);

        $admission->update(['admitted_at' => now()->subDays(30), 'revoked_at' => null, 'revoked_by' => null]);
        $this->assertGuestPhotoAccessDenied($guest, $base, $photo);

        $admission->update(['admitted_at' => now()]);
        $event->delete();
        $this->assertGuestPhotoAccessDenied($guest, $base, $photo);
    }

    public function test_configured_lifetime_changes_existing_admission_authority_immediately(): void
    {
        [$family, $owner] = $this->family('guest-config-lifetime');
        [$guest, $membership] = $this->membership($family, FamilySpaceRole::Guest);
        $event = $this->event($family, $owner, 'Garden party');
        $this->album($family, $owner, $event, GuestParticipation::View);
        $this->admit($family, $event, $membership)->update(['admitted_at' => now()->subDays(20)]);
        $url = "/api/families/{$family->slug}/events/{$event->id}";

        config(['events.admission_lifetime_days' => 30]);
        $this->actingAs($guest)->getJson($url)->assertOk();
        config(['events.admission_lifetime_days' => 10]);
        $this->actingAs($guest)->getJson($url)->assertForbidden();
        config(['events.admission_lifetime_days' => 40]);
        $this->actingAs($guest)->getJson($url)->assertOk();
    }

    public function test_guest_contribution_requires_contribute_participation_or_an_event_scoped_grant(): void
    {
        [$family, $owner] = $this->family('guest-contribution');
        [$guest, $membership] = $this->membership($family, FamilySpaceRole::Guest);
        $event = $this->event($family, $owner, 'Reception');
        $viewAlbum = $this->album($family, $owner, $event, GuestParticipation::View);
        $contributeAlbum = $this->album($family, $owner, $event, GuestParticipation::Contribute);
        $grantedAlbum = $this->album($family, $owner, $event, GuestParticipation::None, AlbumVisibility::Selected);
        $this->admit($family, $event, $membership);
        $base = "/api/families/{$family->slug}/albums";

        $this->actingAs($guest)->postJson("{$base}/{$viewAlbum->id}/media-uploads", [
            'client_filename' => 'view-only.jpg', 'client_mime_type' => 'image/jpeg',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $this->actingAs($owner)->putJson("{$base}/{$grantedAlbum->id}/grants", [
            'membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => true,
        ])->assertCreated();
        $this->actingAs($guest)->getJson("{$base}/{$grantedAlbum->id}")
            ->assertOk()->assertJsonPath('data.permissions.can_contribute', true);
        $this->actingAs($guest)->getJson("{$base}/{$contributeAlbum->id}")
            ->assertOk()->assertJsonPath('data.permissions.can_contribute', true);

        $contributedPhoto = $this->photo($family, $owner, $contributeAlbum);
        $this->actingAs($guest)->getJson("/api/families/{$family->slug}/media-uploads/{$contributedPhoto->media_upload_id}/original")
            ->assertOk();

        $grantedPhoto = $this->photo($family, $owner, $grantedAlbum);
        $this->actingAs($guest)->getJson("/api/families/{$family->slug}/media-uploads/{$grantedPhoto->media_upload_id}/canonical")
            ->assertOk();
        $this->actingAs($guest)->getJson("/api/families/{$family->slug}/media-uploads/{$grantedPhoto->media_upload_id}/original")
            ->assertForbidden();
    }

    public function test_event_invitation_creates_a_guest_but_never_demotes_an_existing_member(): void
    {
        [$family, $owner] = $this->family('event-invitations');
        $event = $this->event($family, $owner, 'Wedding');
        foreach ([FamilySpaceRole::Owner, FamilySpaceRole::Administrator, FamilySpaceRole::Member, FamilySpaceRole::Contributor] as $index => $role) {
            [$member, $memberMembership] = $this->membership($family, $role);
            $member->update(['email' => "existing-{$index}@example.test"]);
            $memberToken = $this->issueEventInvitation($family, $event, $owner, $member->email);
            $memberClaim = $this->postJson('/api/invitations/exchange', ['token' => $memberToken])
                ->assertOk()->assertJsonPath('data.event.id', $event->id)->json('data.claim_token');
            $this->actingAs($member)->postJson('/api/invitations/accept', ['claim_token' => $memberClaim])
                ->assertCreated()->assertJsonPath('data.family_slug', $family->slug)
                ->assertJsonPath('data.event_id', $event->id);
            $this->assertSame($role, $memberMembership->refresh()->role);
            $this->assertDatabaseHas('event_admissions', [
                'event_id' => $event->id, 'family_space_membership_id' => $memberMembership->id,
            ]);
        }

        $guestToken = $this->issueEventInvitation($family, $event, $owner, 'guest@example.test');
        $guestClaim = $this->postJson('/api/invitations/exchange', ['token' => $guestToken])->json('data.claim_token');
        $this->postJson('/api/invitations/accept', [
            'claim_token' => $guestClaim, 'name' => 'Wedding Guest',
            'password' => 'a-very-long-passphrase', 'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'Europe/London',
        ])->assertCreated();
        $guest = User::query()->where('email', 'guest@example.test')->sole();
        $this->assertDatabaseHas('family_space_memberships', [
            'family_space_id' => $family->id, 'user_id' => $guest->id,
            'role' => FamilySpaceRole::Guest->value,
        ]);
    }

    public function test_two_event_invitations_accept_in_either_order_and_reuse_one_membership(): void
    {
        [$family, $owner] = $this->family('two-event-invitations');
        $first = $this->event($family, $owner, 'Ceremony');
        $second = $this->event($family, $owner, 'Reception');
        $email = 'two-events@example.test';
        $firstToken = $this->issueEventInvitation($family, $first, $owner, $email);
        $secondToken = $this->issueEventInvitation($family, $second, $owner, $email);
        $firstClaim = $this->postJson('/api/invitations/exchange', ['token' => $firstToken])->json('data.claim_token');
        $secondClaim = $this->postJson('/api/invitations/exchange', ['token' => $secondToken])->json('data.claim_token');

        $this->postJson('/api/invitations/accept', [
            'claim_token' => $secondClaim, 'name' => 'Two Event Guest',
            'password' => 'a-very-long-passphrase', 'password_confirmation' => 'a-very-long-passphrase',
            'timezone' => 'Europe/London',
        ])->assertCreated();
        $guest = User::query()->where('email', $email)->sole();
        $this->actingAs($guest)->postJson('/api/invitations/accept', ['claim_token' => $firstClaim])->assertCreated();

        $this->assertSame(1, FamilySpaceMembership::query()
            ->where('family_space_id', $family->id)->where('user_id', $guest->id)->count());
        $this->assertSame(2, EventAdmission::query()->where('family_space_id', $family->id)
            ->where('family_space_membership_id', FamilySpaceMembership::query()
                ->where('family_space_id', $family->id)->where('user_id', $guest->id)->value('id'))
            ->count());
    }

    public function test_only_administrators_may_change_admissions(): void
    {
        [$family, $owner] = $this->family('admission-authority');
        [$member] = $this->membership($family, FamilySpaceRole::Member);
        [$guest, $guestMembership] = $this->membership($family, FamilySpaceRole::Guest);
        $event = $this->event($family, $owner, 'Private party');
        $url = "/api/families/{$family->slug}/events/{$event->id}/admissions";

        $this->actingAs($member)->postJson($url, ['membership_id' => $guestMembership->id])->assertForbidden();
        $this->actingAs($guest)->postJson($url, ['membership_id' => $guestMembership->id])->assertForbidden();
        $this->actingAs($owner)->postJson($url, ['membership_id' => $guestMembership->id])->assertCreated();
    }

    /** @return array{FamilySpace, User} */
    private function family(string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner);

        return [$family, $owner];
    }

    /** @return array{User, FamilySpaceMembership} */
    private function membership(FamilySpace $family, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $user->id, 'role' => $role,
        ]);

        return [$user, $membership];
    }

    private function event(FamilySpace $family, User $creator, string $name): FamilyEvent
    {
        return FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $creator->id, 'name' => $name,
        ]);
    }

    private function album(
        FamilySpace $family,
        User $creator,
        ?FamilyEvent $event,
        GuestParticipation $participation,
        AlbumVisibility $visibility = AlbumVisibility::FamilySpace,
    ): Album {
        return Album::query()->create([
            'family_space_id' => $family->id, 'created_by' => $creator->id,
            'name' => fake()->words(2, true), 'visibility' => $visibility,
            'event_id' => $event?->id, 'guest_participation' => $participation,
        ]);
    }

    private function photo(FamilySpace $family, User $creator, Album $album): Photo
    {
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id, 'created_by' => $creator->id,
        ]);
        $photo->mediaUpload->update([
            'original_object_key' => "families/{$family->id}/media/{$photo->media_upload_id}/original.jpg",
            'original_sha256' => hash('sha256', 'original'),
            'detected_mime_type' => 'image/jpeg',
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $creator->id,
        ]);

        return $photo;
    }

    private function admit(FamilySpace $family, FamilyEvent $event, FamilySpaceMembership $membership): EventAdmission
    {
        return EventAdmission::query()->create([
            'family_space_id' => $family->id, 'event_id' => $event->id,
            'family_space_membership_id' => $membership->id, 'admitted_at' => now(),
        ]);
    }

    private function assertGuestPhotoAccessDenied(User $guest, string $base, Photo $photo): void
    {
        $albumId = $photo->albums()->value('albums.id');
        $this->actingAs($guest)->getJson("{$base}/photos/{$photo->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("{$base}/media-uploads/{$photo->media_upload_id}/canonical")
            ->assertForbidden();
        $this->actingAs($guest)->getJson("{$base}/media-uploads/{$photo->media_upload_id}/original")
            ->assertForbidden();
        $this->actingAs($guest)->postJson("{$base}/photos/{$photo->id}/comments", [
            'body' => 'Denied', 'album_id' => $albumId,
        ])
            ->assertNotFound();
        $this->actingAs($guest)->putJson("{$base}/photos/{$photo->id}/reaction", [
            'reaction' => 'love', 'album_id' => $albumId,
        ])
            ->assertNotFound();
    }

    private function issueEventInvitation(
        FamilySpace $family,
        FamilyEvent $event,
        User $owner,
        string $email,
    ): string {
        Notification::fake();
        $rawToken = null;
        $this->actingAs($owner)->postJson("/api/families/{$family->slug}/invitations", [
            'email' => $email, 'event_id' => $event->id,
        ])->assertCreated()->assertJsonPath('data.role', FamilySpaceRole::Guest->value);
        Notification::assertSentOnDemand(
            InvitationIssued::class,
            function (InvitationIssued $notification, array $channels, AnonymousNotifiable $notifiable) use (&$rawToken): bool {
                parse_str((string) parse_url(str_replace('#', '?', $notification->acceptUrl), PHP_URL_QUERY), $parameters);
                $rawToken = $parameters['token'] ?? null;

                return $channels === ['mail'] && is_string($rawToken);
            },
        );
        $this->assertIsString($rawToken);

        return $rawToken;
    }
}

class EventGuestMediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
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
