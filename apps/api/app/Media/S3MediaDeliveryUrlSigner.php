<?php

namespace App\Media;

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class S3MediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
{
    private S3Client $client;

    public function __construct()
    {
        $configuration = [
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => (string) config('filesystems.disks.s3.key'),
                'secret' => (string) config('filesystems.disks.s3.secret'),
            ],
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint'),
            'request_checksum_calculation' => 'when_required',
        ];
        $endpoint = config('media.upload.public_endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $configuration['endpoint'] = $endpoint;
        }

        $this->client = new S3Client($configuration);
    }

    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
    ): MediaDeliveryAuthorization {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
            'ResponseContentType' => $responseContentType,
        ]);
        $request = $this->client->createPresignedRequest($command, $expiresAt);

        return new MediaDeliveryAuthorization(
            (string) $request->getUri(),
            CarbonImmutable::instance($expiresAt),
        );
    }
}
