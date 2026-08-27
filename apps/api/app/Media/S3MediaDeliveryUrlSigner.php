<?php

namespace App\Media;

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class S3MediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
{
    private S3Client $browserClient;

    private S3Client $serviceClient;

    public function __construct()
    {
        $configuration = [
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.s3.region'),
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint'),
            'request_checksum_calculation' => 'when_required',
        ];
        $key = config('filesystems.disks.s3.key');
        if (is_string($key) && $key !== '') {
            $configuration['credentials'] = [
                'key' => $key,
                'secret' => (string) config('filesystems.disks.s3.secret'),
            ];
        }
        $this->browserClient = new S3Client($this->withEndpoint(
            $configuration,
            config('media.upload.public_endpoint'),
        ));
        $this->serviceClient = new S3Client($this->withEndpoint(
            $configuration,
            config('filesystems.disks.s3.endpoint'),
        ));
    }

    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        $client = $audience === MediaSigningAudience::Service
            ? $this->serviceClient
            : $this->browserClient;
        $command = $client->getCommand('GetObject', [
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
            'ResponseContentType' => $responseContentType,
        ]);
        $request = $client->createPresignedRequest($command, $expiresAt);

        return new MediaDeliveryAuthorization(
            (string) $request->getUri(),
            CarbonImmutable::instance($expiresAt),
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function withEndpoint(array $configuration, mixed $endpoint): array
    {
        if (is_string($endpoint) && $endpoint !== '') {
            $configuration['endpoint'] = $endpoint;
        }

        return $configuration;
    }
}
