<?php

namespace Tests\Feature;

use App\Media\MediaObjectCollision;
use App\Media\MediaSigningAudience;
use App\Media\S3FamilyMediaStorageCleaner;
use App\Media\S3MediaDeliveryUrlSigner;
use App\Media\S3MediaObjectStorage;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class S3MediaObjectStorageTest extends TestCase
{
    public function test_read_authority_is_key_scoped_and_short_lived(): void
    {
        $this->configureStorage();
        $key = 'families/01KTEST/media/01KUPLOAD/variants/display.v1.webp';
        $expiresAt = now()->addMinutes(5);

        $authorization = (new S3MediaDeliveryUrlSigner)->authorizeRead(
            $key,
            'image/webp',
            $expiresAt,
            MediaSigningAudience::Browser,
        );
        parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('http://localhost:4570/fambam-media/', $authorization->url);
        $this->assertSame("/fambam-media/{$key}", parse_url($authorization->url, PHP_URL_PATH));
        $this->assertContains($query['X-Amz-Expires'] ?? null, ['299', '300']);
        $this->assertSame('image/webp', $query['response-content-type'] ?? null);
        $this->assertTrue($authorization->expiresAt->equalTo($expiresAt));
    }

    public function test_upload_authority_is_key_scoped_short_lived_and_write_once(): void
    {
        $this->configureStorage();
        $key = 'families/01KTEST/media-staging/01KUPLOAD/original';
        $expiresAt = now()->addMinutes(15);

        $authorization = (new S3MediaObjectStorage)->authorizeSingleWrite(
            $key,
            $expiresAt,
            MediaSigningAudience::Browser,
        );

        $this->assertStringStartsWith('http://localhost:4570/fambam-media/', $authorization->url);
        $this->assertSame("/fambam-media/{$key}", parse_url($authorization->url, PHP_URL_PATH));
        $this->assertSame('*', $authorization->headers['If-None-Match']);
        $this->assertStringContainsString('if-none-match', strtolower($authorization->url));
        $this->assertTrue($authorization->expiresAt->equalTo($expiresAt));
    }

    public function test_service_audience_authorities_use_the_docker_reachable_endpoint(): void
    {
        $this->configureStorage();
        $key = 'families/01KTEST/face-analysis/01KATTEMPT/result.json';
        $expiresAt = now()->addMinutes(15);

        $read = (new S3MediaDeliveryUrlSigner)->authorizeRead(
            'families/01KTEST/media/01KUPLOAD/canonical.jpg',
            'image/jpeg',
            $expiresAt,
            MediaSigningAudience::Service,
        );
        $write = (new S3MediaObjectStorage)->authorizeSingleWrite(
            $key,
            $expiresAt,
            MediaSigningAudience::Service,
        );

        $this->assertStringStartsWith('http://localstack:4566/fambam-media/', $read->url);
        $this->assertStringStartsWith('http://localstack:4566/fambam-media/', $write->url);
        $this->assertSame('*', $write->headers['If-None-Match']);
    }

    public function test_real_signed_read_authority_supports_range_requests(): void
    {
        if (! config('media.integration_test_enabled')) {
            $this->markTestSkipped('The real S3-compatible storage regression runs with infrastructure smoke.');
        }

        $key = 'families/01KTEST/media/'.Str::ulid().'/variants/display.v1.webp';
        $disk = Storage::disk('s3');
        $disk->put($key, '0123456789');

        try {
            $authorization = (new S3MediaDeliveryUrlSigner)->authorizeRead(
                $key,
                'image/webp',
                now()->addMinutes(2),
                MediaSigningAudience::Browser,
            );
            $tamperedUrl = preg_replace(
                '/X-Amz-Signature=[^&]+/',
                'X-Amz-Signature='.str_repeat('0', 64),
                $authorization->url,
            );
            $this->assertIsString($tamperedUrl);
            $this->assertSame(403, Http::get($tamperedUrl)->status());

            $response = Http::withHeaders(['Range' => 'bytes=2-5'])->get($authorization->url);

            $this->assertSame(206, $response->status());
            $this->assertSame('image/webp', $response->header('Content-Type'));
            $this->assertSame('2345', $response->body());
        } finally {
            $disk->delete($key);
        }
    }

    public function test_real_bucket_blocks_public_access_and_allows_delivery_cors(): void
    {
        if (! config('media.integration_test_enabled')) {
            $this->markTestSkipped('The real S3-compatible storage regression runs with infrastructure smoke.');
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.s3.region'),
            'endpoint' => (string) config('filesystems.disks.s3.endpoint'),
            'credentials' => [
                'key' => (string) config('filesystems.disks.s3.key'),
                'secret' => (string) config('filesystems.disks.s3.secret'),
            ],
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint'),
        ]);
        $bucket = (string) config('filesystems.disks.s3.bucket');
        $publicAccess = $client->getPublicAccessBlock(['Bucket' => $bucket])['PublicAccessBlockConfiguration'];
        $cors = $client->getBucketCors(['Bucket' => $bucket])['CORSRules'][0];

        foreach (['BlockPublicAcls', 'IgnorePublicAcls', 'BlockPublicPolicy', 'RestrictPublicBuckets'] as $setting) {
            $this->assertTrue($publicAccess[$setting]);
        }
        $this->assertEqualsCanonicalizing(['PUT', 'GET', 'HEAD'], $cors['AllowedMethods']);
        $this->assertContains('Content-Range', $cors['ExposeHeaders']);
        $this->assertContains('Accept-Ranges', $cors['ExposeHeaders']);
    }

    public function test_real_family_media_cleanup_is_prefix_scoped_and_idempotent(): void
    {
        if (! config('media.integration_test_enabled')) {
            $this->markTestSkipped('The real S3-compatible storage regression runs with infrastructure smoke.');
        }

        $familyId = '01KDELETEFAMILYMEDIA00000';
        $otherFamilyId = '01KKEEPFAMILYMEDIA000000';
        config(['media.cleanup.storage_delete_page_size' => 2]);
        $disk = Storage::disk('s3');
        $familyKeys = [
            "families/{$familyId}/media-staging/upload/original",
            "families/{$familyId}/media/upload/original.jpg",
            "families/{$familyId}/media/upload/variants/display.v1.webp",
            "families/{$familyId}/media/upload/variants/thumbnail.v1.webp",
            "families/{$familyId}/quarantine/upload/original.jpg",
        ];
        $otherKey = "families/{$otherFamilyId}/media/upload/original.jpg";
        foreach ([...$familyKeys, $otherKey] as $key) {
            $disk->put($key, 'bytes');
        }

        try {
            $cleaner = new S3FamilyMediaStorageCleaner;
            $cleaner->deleteFamilyMedia($familyId);
            $cleaner->deleteFamilyMedia($familyId);

            foreach ($familyKeys as $key) {
                $this->assertFalse($disk->exists($key));
            }
            $this->assertTrue($disk->exists($otherKey));
        } finally {
            foreach ([...$familyKeys, $otherKey] as $key) {
                $disk->delete($key);
            }
        }
    }

    public function test_reusing_real_upload_authority_cannot_replace_staged_bytes(): void
    {
        if (! config('media.integration_test_enabled')) {
            $this->markTestSkipped('The real S3-compatible storage regression runs with infrastructure smoke.');
        }

        $key = 'families/01KTEST/media-staging/'.Str::ulid().'/original';
        $disk = Storage::disk('s3');
        $disk->delete($key);

        try {
            $authorization = (new S3MediaObjectStorage)->authorizeSingleWrite(
                $key,
                now()->addMinutes(2),
                MediaSigningAudience::Browser,
            );
            $first = Http::withHeaders($authorization->headers)
                ->withBody('first-preserved-bytes')
                ->put($authorization->url);
            $second = Http::withHeaders($authorization->headers)
                ->withBody('replacement-bytes')
                ->put($authorization->url);

            $this->assertTrue(
                $first->successful(),
                "Initial upload failed with {$first->status()}: {$first->body()}",
            );
            $this->assertSame(412, $second->status());
            $this->assertSame('first-preserved-bytes', $disk->get($key));
        } finally {
            $disk->delete($key);
        }
    }

    public function test_real_finalisation_is_idempotent_only_for_identical_preserved_bytes(): void
    {
        if (! config('media.integration_test_enabled')) {
            $this->markTestSkipped('The real S3-compatible storage regression runs with infrastructure smoke.');
        }

        $key = 'families/01KTEST/media/'.Str::ulid().'/original.jpg';
        $disk = Storage::disk('s3');
        $storage = new S3MediaObjectStorage;
        $firstPath = tempnam(sys_get_temp_dir(), 'fambam-final-first-');
        $secondPath = tempnam(sys_get_temp_dir(), 'fambam-final-second-');
        $this->assertIsString($firstPath);
        $this->assertIsString($secondPath);
        file_put_contents($firstPath, 'first-preserved-bytes');
        file_put_contents($secondPath, 'replacement-bytes');
        $disk->delete($key);

        try {
            $checksum = hash_file('sha256', $firstPath);
            $this->assertIsString($checksum);
            $storage->finalizeWriteOnce($firstPath, $key, $checksum);
            $storage->finalizeWriteOnce($firstPath, $key, $checksum);

            $this->expectException(MediaObjectCollision::class);
            $storage->finalizeWriteOnce(
                $secondPath,
                $key,
                (string) hash_file('sha256', $secondPath),
            );
        } finally {
            $this->assertSame('first-preserved-bytes', $disk->get($key));
            $disk->delete($key);
            @unlink($firstPath);
            @unlink($secondPath);
        }
    }

    private function configureStorage(): void
    {
        config([
            'filesystems.disks.s3.key' => 'test',
            'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.region' => 'eu-west-2',
            'filesystems.disks.s3.bucket' => 'fambam-media',
            'filesystems.disks.s3.endpoint' => 'http://localstack:4566',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
            'media.upload.public_endpoint' => 'http://localhost:4570',
        ]);
    }
}
