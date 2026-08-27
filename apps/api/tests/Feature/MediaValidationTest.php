<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Jobs\GenerateCanonicalMediaUpload;
use App\Media\DecodedImage;
use App\Media\ImageDecoderValidator;
use App\Media\MalwareScanner;
use App\Media\MediaObjectCollision;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\MediaValidationFailed;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\MediaValidationManager;
use App\Tenancy\TenantOperationContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaValidationTest extends TestCase
{
    use RefreshDatabase;

    private ValidationStorage $storage;

    private ValidationDecoder $decoder;

    private ValidationMalwareScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->storage = new ValidationStorage;
        $this->decoder = new ValidationDecoder;
        $this->scanner = new ValidationMalwareScanner;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(ImageDecoderValidator::class, $this->decoder);
        $this->app->instance(MalwareScanner::class, $this->scanner);
    }

    #[DataProvider('acceptedFormats')]
    public function test_it_detects_decodes_scans_and_preserves_every_accepted_format(
        string $bytes,
        string $extension,
        string $mimeType,
    ): void {
        [$upload, $context] = $this->uploadedMedia($bytes, 'misleading.exe', 'application/octet-stream');

        app(MediaValidationManager::class)->validate($context, $upload->id);

        $upload->refresh();
        $expectedKey = "families/{$upload->family_space_id}/media/{$upload->id}/original.{$extension}";
        $this->assertSame(MediaUploadState::Preserved, $upload->state);
        $this->assertSame($expectedKey, $upload->original_object_key);
        $this->assertSame($mimeType, $upload->detected_mime_type);
        $this->assertSame(hash('sha256', $bytes), $upload->original_sha256);
        $this->assertSame($bytes, $this->storage->objects[$expectedKey]);
        $this->assertArrayNotHasKey($upload->staging_object_key, $this->storage->objects);
        $this->assertSame([$upload->staging_object_key], $this->storage->downloadedKeys);
        $this->assertSame(1, $this->decoder->calls);
        $this->assertSame(1, $this->scanner->calls);
        Queue::assertPushed(GenerateCanonicalMediaUpload::class, function (GenerateCanonicalMediaUpload $job) use ($upload): bool {
            return $job->mediaUploadId === $upload->id
                && $job->sourceSha256 === $upload->original_sha256
                && $job->context['family_space_id'] === $upload->family_space_id;
        });
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $upload->family_space_id,
            'action' => 'media_upload.original_accepted',
            'subject_id' => $upload->id,
        ]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function acceptedFormats(): iterable
    {
        yield 'JPEG' => ["\xFF\xD8\xFFvalidated-jpeg", 'jpg', 'image/jpeg'];
        yield 'PNG' => ["\x89PNG\r\n\x1A\nvalidated-png", 'png', 'image/png'];
        yield 'HEIC' => ["\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heic", 'heic', 'image/heic'];
        yield 'HEIF' => ["\x00\x00\x00\x18ftypmif1\x00\x00\x00\x00heif", 'heif', 'image/heif'];
        yield 'WebP' => ['RIFF'."\x10\x00\x00\x00".'WEBPvalidated', 'webp', 'image/webp'];
        yield 'TIFF little endian' => ["II\x2A\x00validated-tiff", 'tif', 'image/tiff'];
        yield 'TIFF big endian' => ["MM\x00\x2Avalidated-tiff", 'tif', 'image/tiff'];
    }

    public function test_unsupported_or_decoder_invalid_files_are_quarantined_under_server_derived_keys(): void
    {
        [$unknown, $unknownContext] = $this->uploadedMedia('not-an-image', 'photo.jpg', 'image/jpeg');
        app(MediaValidationManager::class)->validate($unknownContext, $unknown->id);
        $unknown->refresh();

        $this->assertSame(MediaUploadState::Quarantined, $unknown->state);
        $this->assertSame('unsupported_format', $unknown->rejection_reason);
        $this->assertStringEndsWith('/original.bin', $unknown->quarantine_object_key);
        $this->assertNull($unknown->detected_mime_type);
        $this->assertSame(hash('sha256', 'not-an-image'), $unknown->original_sha256);
        $this->assertSame(0, $this->decoder->calls);

        [$avif, $avifContext] = $this->uploadedMedia(
            "\x00\x00\x00\x18ftypavif\x00\x00\x00\x00mif1",
            'not-supported.heif',
            'image/heif',
        );
        app(MediaValidationManager::class)->validate($avifContext, $avif->id);
        $this->assertSame('unsupported_format', $avif->refresh()->rejection_reason);

        $this->decoder->failureReason = 'invalid_image';
        [$invalid, $invalidContext] = $this->uploadedMedia("\x89PNG\r\n\x1A\ncorrupt", 'valid.png', 'image/png');
        app(MediaValidationManager::class)->validate($invalidContext, $invalid->id);
        $invalid->refresh();

        $this->assertSame(MediaUploadState::Quarantined, $invalid->state);
        $this->assertSame('invalid_image', $invalid->rejection_reason);
        $this->assertStringEndsWith('/original.png', $invalid->quarantine_object_key);
        $this->assertSame('image/png', $invalid->detected_mime_type);
    }

    #[DataProvider('scannerFailures')]
    public function test_malware_and_scanner_failures_are_fail_closed(string $reason): void
    {
        $this->scanner->failureReason = $reason;
        [$upload, $context] = $this->uploadedMedia("\xFF\xD8\xFFscan-me", 'photo.jpg', 'image/jpeg');

        app(MediaValidationManager::class)->validate($context, $upload->id);

        $upload->refresh();
        $this->assertSame(MediaUploadState::Quarantined, $upload->state);
        $this->assertSame($reason, $upload->rejection_reason);
        $this->assertSame(hash('sha256', "\xFF\xD8\xFFscan-me"), $upload->original_sha256);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'media_upload.original_quarantined',
            'subject_id' => $upload->id,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function scannerFailures(): iterable
    {
        yield 'infected' => ['malware_detected'];
        yield 'remote failure' => ['malware_scanner_unavailable'];
        yield 'timeout' => ['malware_scanner_timeout'];
    }

    public function test_replayed_validation_is_a_no_op_and_cannot_change_preserved_bytes_or_checksum(): void
    {
        $bytes = "\xFF\xD8\xFFfirst-original";
        [$upload, $context] = $this->uploadedMedia($bytes, 'photo.jpg', 'image/jpeg');
        $validation = app(MediaValidationManager::class);
        $validation->validate($context, $upload->id);
        $preserved = $upload->refresh();
        $key = (string) $preserved->original_object_key;
        $checksum = $preserved->original_sha256;

        $this->storage->objects[$upload->staging_object_key] = "\xFF\xD8\xFFreplacement";
        $validation->validate($context, $upload->id);

        $this->assertSame($bytes, $this->storage->objects[$key]);
        $this->assertSame($checksum, $upload->refresh()->original_sha256);
        $this->assertSame(1, $this->getConnection()->table('audit_events')
            ->where('action', 'media_upload.original_accepted')
            ->where('subject_id', $upload->id)
            ->count());
    }

    public function test_a_different_object_at_the_final_key_is_never_overwritten(): void
    {
        [$upload, $context] = $this->uploadedMedia("\xFF\xD8\xFFnew", 'photo.jpg', 'image/jpeg');
        $key = "families/{$upload->family_space_id}/media/{$upload->id}/original.jpg";
        $this->storage->objects[$key] = 'existing-immutable-bytes';
        $this->storage->checksums[$key] = hash('sha256', 'existing-immutable-bytes');

        try {
            app(MediaValidationManager::class)->validate($context, $upload->id);
            $this->fail('A conflicting final object was accepted.');
        } catch (MediaObjectCollision) {
            $this->assertSame('existing-immutable-bytes', $this->storage->objects[$key]);
            $this->assertSame(MediaUploadState::Verifying, $upload->refresh()->state);
        }
    }

    /** @return array{MediaUpload, TenantOperationContext} */
    private function uploadedMedia(string $bytes, string $filename, string $mimeType): array
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $upload = MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Uploaded,
            'staging_object_key' => "families/{$family->id}/media-staging/{$id}/original",
            'client_filename' => $filename,
            'client_mime_type' => $mimeType,
            'byte_size' => strlen($bytes),
            'uploaded_at' => now(),
        ]);
        $this->storage->objects[$upload->staging_object_key] = $bytes;
        $context = new TenantOperationContext(
            $family->id,
            $user->id,
            $upload->correlation_id,
            $upload->traceparent,
        );

        return [$upload, $context];
    }
}

class ValidationStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $checksums = [];

    /** @var list<string> */
    public array $downloadedKeys = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used by validation tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return isset($this->objects[$key])
            ? new StoredObject(strlen($this->objects[$key]), $this->checksums[$key] ?? null)
            : null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        $this->downloadedKeys[] = $key;
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $bytes = (string) file_get_contents($localPath);
        if (isset($this->objects[$key])) {
            if ($this->objects[$key] === $bytes && hash_equals($this->checksums[$key] ?? '', $sha256)) {
                return;
            }

            throw new MediaObjectCollision('Conflicting immutable object.');
        }
        $this->objects[$key] = $bytes;
        $this->checksums[$key] = $sha256;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key], $this->checksums[$key]);
    }
}

class ValidationDecoder implements ImageDecoderValidator
{
    public int $calls = 0;

    public ?string $failureReason = null;

    public function validate(string $path): DecodedImage
    {
        $this->calls++;
        if ($this->failureReason !== null) {
            throw new MediaValidationFailed($this->failureReason);
        }

        return new DecodedImage(100, 100);
    }
}

class ValidationMalwareScanner implements MalwareScanner
{
    public int $calls = 0;

    public ?string $failureReason = null;

    public function assertClean(string $path): void
    {
        $this->calls++;
        if ($this->failureReason !== null) {
            throw new MediaValidationFailed($this->failureReason);
        }
    }
}
