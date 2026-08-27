<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\AuditEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeMediaDeliveryUrlSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-10T12:00:00+00:00');
        $this->signer = new FakeMediaDeliveryUrlSigner;
        $this->app->instance(MediaDeliveryUrlSigner::class, $this->signer);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_administrator_and_member_receive_short_lived_key_scoped_delivery_authority(): void
    {
        foreach ([FamilySpaceRole::Owner, FamilySpaceRole::Administrator, FamilySpaceRole::Member] as $index => $role) {
            [$family, $user, $upload, $variant] = $this->readyUpload($role, "delivery-{$index}");
            $base = "/api/families/{$family->slug}/media-uploads/{$upload->id}";

            $this->actingAs($user)->getJson("{$base}/canonical")
                ->assertOk()
                ->assertJsonPath('data.asset', 'canonical')
                ->assertJsonPath('data.method', 'GET')
                ->assertJsonPath('data.expires_at', '2026-08-10T12:05:00+00:00')
                ->assertJsonMissingPath('data.gps_latitude')
                ->assertJsonMissingPath('data.original_exif_base64');

            $this->actingAs($user)->getJson("{$base}/variants/thumbnail")
                ->assertOk()
                ->assertJsonPath('data.asset', 'variant')
                ->assertJsonPath('data.transform_name', 'thumbnail')
                ->assertJsonPath('data.processing_version', 1);

            $this->actingAs($user)->getJson("{$base}/original")
                ->assertOk()
                ->assertJsonPath('data.asset', 'original')
                ->assertJsonPath('data.method', 'GET');

            $this->assertSame([
                [$upload->canonical_object_key, 'image/jpeg'],
                [$variant->object_key, 'image/webp'],
                [$upload->original_object_key, 'image/jpeg'],
            ], array_slice($this->signer->authorizedAssets, -3));
        }

        $this->assertDatabaseCount('audit_events', 3);
        $this->assertSame(
            ['original_download_authorised'],
            AuditEvent::query()->pluck('action')->unique()->values()->all(),
        );
        $this->assertDatabaseMissing('audit_events', ['action' => 'original_downloaded']);
        $this->assertSame(
            '2026-08-10T12:05:00+00:00',
            AuditEvent::query()->firstOrFail()->metadata['expires_at'],
        );
    }

    public function test_contributor_and_guest_have_no_default_delivery_access(): void
    {
        foreach ([FamilySpaceRole::Contributor, FamilySpaceRole::Guest] as $index => $role) {
            [$family, $user, $upload] = $this->readyUpload($role, "denied-delivery-{$index}");
            $base = "/api/families/{$family->slug}/media-uploads/{$upload->id}";

            $this->actingAs($user)->getJson("{$base}/canonical")->assertForbidden();
            $this->actingAs($user)->getJson("{$base}/variants/thumbnail")->assertForbidden();
            $this->actingAs($user)->getJson("{$base}/original")->assertForbidden();
        }

        $this->assertSame([], $this->signer->authorizedAssets);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_cross_tenant_ids_and_unavailable_assets_fail_closed(): void
    {
        [$firstFamily, $firstUser, $firstUpload] = $this->readyUpload(FamilySpaceRole::Member, 'first-delivery');
        [$secondFamily, $secondUser, $secondUpload] = $this->readyUpload(FamilySpaceRole::Member, 'second-delivery');

        $this->getJson("/api/families/{$firstFamily->slug}/media-uploads/{$firstUpload->id}/original")
            ->assertUnauthorized();
        $this->actingAs($secondUser)
            ->getJson("/api/families/{$secondFamily->slug}/media-uploads/{$firstUpload->id}/original")
            ->assertNotFound();
        $this->actingAs($secondUser)
            ->getJson("/api/families/{$firstFamily->slug}/media-uploads/{$firstUpload->id}/original")
            ->assertNotFound();

        $secondUpload->update(['state' => MediaUploadState::Processing]);
        $base = "/api/families/{$secondFamily->slug}/media-uploads/{$secondUpload->id}";
        $this->actingAs($secondUser)->getJson("{$base}/canonical")->assertNotFound();
        $this->actingAs($secondUser)->getJson("{$base}/variants/not-a-transform")->assertNotFound();

        $this->assertSame([], $this->signer->authorizedAssets);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertNotSame($firstUser->id, $secondUser->id);
    }

    public function test_original_audit_is_not_written_when_url_signing_fails(): void
    {
        [$family, $user, $upload] = $this->readyUpload(FamilySpaceRole::Member, 'failed-signing');
        $this->signer->fail = true;

        $this->actingAs($user)
            ->getJson("/api/families/{$family->slug}/media-uploads/{$upload->id}/original")
            ->assertInternalServerError();

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_delivery_authority_configuration_cannot_exceed_the_short_lived_cap(): void
    {
        config(['media.delivery.authority_ttl_minutes' => 120]);
        [$family, $user, $upload] = $this->readyUpload(FamilySpaceRole::Member, 'bounded-signing');

        $this->actingAs($user)
            ->getJson("/api/families/{$family->slug}/media-uploads/{$upload->id}/canonical")
            ->assertOk()
            ->assertJsonPath('data.expires_at', '2026-08-10T12:15:00+00:00');
    }

    /** @return array{FamilySpace, User, MediaUpload, MediaVariant} */
    private function readyUpload(FamilySpaceRole $role, string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $uploadId = (string) Str::ulid();
        $upload = MediaUpload::factory()->create([
            'id' => $uploadId,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Ready,
            'original_object_key' => "families/{$family->id}/media/{$uploadId}/original.jpg",
            'original_sha256' => hash('sha256', 'original'),
            'detected_mime_type' => 'image/jpeg',
            'canonical_object_key' => "families/{$family->id}/media/{$uploadId}/canonical.jpg",
            'canonical_mime_type' => 'image/jpeg',
            'canonical_sha256' => hash('sha256', 'canonical'),
        ]);
        $variant = MediaVariant::query()->create([
            'id' => (string) Str::ulid(),
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

        return [$family, $user, $upload, $variant];
    }
}

class FakeMediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
{
    /** @var list<array{string, string}> */
    public array $authorizedAssets = [];

    public bool $fail = false;

    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        if ($this->fail) {
            throw new \RuntimeException('Signing failed.');
        }

        $this->authorizedAssets[] = [$key, $responseContentType];

        return new MediaDeliveryAuthorization(
            'https://storage.test/'.rawurlencode($key),
            CarbonImmutable::instance($expiresAt),
        );
    }
}
