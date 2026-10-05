<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountSettingsCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new AccountAvatarUrlSigner);
        $this->app->instance(MediaObjectStorage::class, $this->createStub(MediaObjectStorage::class));
    }

    public function test_about_is_trimmed_bounded_and_blank_is_null(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/user/profile', [
            'name' => 'Account Holder',
            'timezone' => 'Europe/London',
            'about' => '  Keeper of the family archive.  ',
        ])->assertOk()->assertJsonPath('data.about', 'Keeper of the family archive.');

        $this->actingAs($user)->patchJson('/api/user/profile', [
            'name' => 'Account Holder', 'timezone' => 'Europe/London', 'about' => '   ',
        ])->assertOk()->assertJsonPath('data.about', null);

        $this->actingAs($user)->patchJson('/api/user/profile', [
            'name' => 'Account Holder', 'timezone' => 'Europe/London', 'about' => str_repeat('a', 1001),
        ])->assertUnprocessable()->assertJsonValidationErrors('about');
    }

    public function test_email_change_keeps_the_old_email_until_the_signed_pending_email_is_verified(): void
    {
        Notification::fake();
        Event::fake([Verified::class]);
        $user = User::factory()->create([
            'email' => 'old@example.test',
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($user)->postJson('/api/user/email-change', [
            'email' => '  NEW@EXAMPLE.TEST ',
            'current_password' => 'current-password',
        ])->assertAccepted()
            ->assertJsonPath('data.email', 'old@example.test')
            ->assertJsonPath('data.pending_email', 'new@example.test');
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertNotNull($user->pending_email_requested_at);

        $verificationUrl = null;
        Notification::assertSentOnDemand(VerifyPendingEmail::class, function (VerifyPendingEmail $notification) use (&$verificationUrl): bool {
            $verificationUrl = $notification->verificationUrl;

            return true;
        });
        $this->assertIsString($verificationUrl);
        $this->actingAs($user)->getJson($verificationUrl)
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.test');
        $this->assertSame('new@example.test', $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->pending_email);
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));
    }

    public function test_email_change_requires_the_current_password_rejects_collisions_and_is_self_only(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('current-password')]);
        $other = User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($user)->postJson('/api/user/email-change', [
            'email' => 'available@example.test', 'current_password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->actingAs($user)->postJson('/api/user/email-change', [
            'email' => $other->email, 'current_password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->actingAs($user)->postJson('/api/user/email-change', [
            'email' => 'available@example.test', 'current_password' => 'current-password',
        ])->assertAccepted();
        $url = '';
        Notification::assertSentOnDemand(VerifyPendingEmail::class, function (VerifyPendingEmail $notification) use (&$url): bool {
            $url = $notification->verificationUrl;

            return true;
        });
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->assertSame($user->email, $user->refresh()->email);
    }

    public function test_account_avatar_can_be_set_replaced_presented_and_removed_only_by_its_owner(): void
    {
        [$user, $first] = $this->avatarUpload('avatar-one.jpg');
        [, $second] = $this->avatarUpload('avatar-two.jpg', $user);
        $other = User::factory()->create();
        $family = $first->familySpace()->firstOrFail();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $other->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $archiveUpload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'purpose' => 'archive',
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/canonical/archive.jpg",
            'canonical_mime_type' => 'image/jpeg',
        ]);

        $this->actingAs($other)->putJson('/api/user/avatar', ['media_upload_id' => $first->id])->assertNotFound();
        $this->actingAs($other)
            ->getJson("/api/families/{$family->slug}/media-uploads/{$first->id}/canonical")
            ->assertForbidden();
        $this->actingAs($user)->putJson('/api/user/avatar', ['media_upload_id' => $archiveUpload->id])->assertNotFound();
        $this->actingAs($user)->putJson('/api/user/avatar', ['media_upload_id' => $first->id])->assertOk();
        $this->actingAs($user)->getJson('/api/user')->assertOk()
            ->assertJsonPath('data.avatar.media_upload_id', $first->id)
            ->assertJsonPath('data.avatar.url', "https://media.example.test/{$first->id}");

        $this->actingAs($user)->putJson('/api/user/avatar', ['media_upload_id' => $second->id])->assertOk();
        $this->assertSame($second->id, $user->refresh()->avatar_media_upload_id);
        $this->assertDatabaseHas('media_uploads', ['id' => $first->id, 'state' => 'ready']);

        $this->actingAs($user)->deleteJson('/api/user/avatar')->assertNoContent();
        $this->assertNull($user->refresh()->avatar_media_upload_id);
        $this->actingAs($user)->getJson('/api/user')->assertJsonPath('data.avatar', null);
    }

    public function test_account_avatar_upload_uses_the_existing_authorized_media_pipeline(): void
    {
        $storage = $this->createMock(MediaObjectStorage::class);
        $storage->method('authorizeSingleWrite')->willReturn(new UploadAuthorization(
            'https://uploads.example.test/avatar',
            ['If-None-Match' => '*'],
            now()->toImmutable()->addMinutes(10),
        ));
        $this->app->instance(MediaObjectStorage::class, $storage);
        $family = FamilySpace::factory()->create(['slug' => 'avatar-upload-family']);
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => FamilySpaceRole::Guest,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'account-avatar-one')
            ->postJson('/api/families/avatar-upload-family/account/avatar-uploads', [
                'client_filename' => 'avatar.jpg',
                'client_mime_type' => 'image/jpeg',
                'upload_batch_id' => (string) Str::ulid(),
            ])->assertCreated()
            ->assertJsonPath('data.upload_authorization.url', 'https://uploads.example.test/avatar');

        $this->assertDatabaseHas('media_uploads', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'purpose' => 'account_avatar',
            'upload_batch_id' => null,
        ]);
    }

    public function test_recent_sign_in_is_bounded_self_only_and_does_not_fabricate_location(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $other = User::factory()->create();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/140.0')
            ->postJson('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertOk();
        $this->getJson('/api/user/recent-sign-ins')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device', 'Chrome on Mac')
            ->assertJsonPath('data.0.location', null)
            ->assertJsonMissingPath('data.0.ip');

        $this->actingAs($other)->getJson('/api/user/recent-sign-ins')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array{User, MediaUpload} */
    private function avatarUpload(string $filename, ?User $user = null): array
    {
        $user ??= User::factory()->create();
        $family = FamilySpace::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'purpose' => 'account_avatar',
            'state' => MediaUploadState::Ready,
            'client_filename' => $filename,
            'canonical_object_key' => "families/{$family->id}/canonical/{$filename}",
            'canonical_mime_type' => 'image/jpeg',
        ]);

        return [$user, $upload];
    }
}

final class AccountAvatarUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        $id = basename($key, '.jpg');
        if (str_starts_with($id, 'avatar-')) {
            $upload = MediaUpload::query()->where('client_filename', "{$id}.jpg")->firstOrFail();
            $id = $upload->id;
        }

        return new MediaDeliveryAuthorization("https://media.example.test/{$id}", CarbonImmutable::instance($expiresAt));
    }
}
