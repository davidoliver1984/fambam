<?php

namespace Tests\Feature;

use App\Media\MediaObjectCollision;
use App\Media\S3MediaObjectStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class S3MediaObjectStorageTest extends TestCase
{
    public function test_upload_authority_is_key_scoped_short_lived_and_write_once(): void
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
        $key = 'families/01KTEST/media-staging/01KUPLOAD/original';
        $expiresAt = now()->addMinutes(15);

        $authorization = (new S3MediaObjectStorage)->authorizeSingleWrite($key, $expiresAt);

        $this->assertStringStartsWith('http://localhost:4570/fambam-media/', $authorization->url);
        $this->assertSame("/fambam-media/{$key}", parse_url($authorization->url, PHP_URL_PATH));
        $this->assertSame('*', $authorization->headers['If-None-Match']);
        $this->assertStringContainsString('if-none-match', strtolower($authorization->url));
        $this->assertTrue($authorization->expiresAt->equalTo($expiresAt));
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
            $authorization = (new S3MediaObjectStorage)->authorizeSingleWrite($key, now()->addMinutes(2));
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
}
