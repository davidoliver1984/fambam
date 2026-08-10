<?php

namespace App\Media;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class S3MediaObjectStorage implements MediaObjectStorage
{
    private S3Client $internalClient;

    private S3Client $signingClient;

    public function __construct()
    {
        $base = [
            'version' => 'latest',
            'region' => (string) config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => (string) config('filesystems.disks.s3.key'),
                'secret' => (string) config('filesystems.disks.s3.secret'),
            ],
            'use_path_style_endpoint' => (bool) config('filesystems.disks.s3.use_path_style_endpoint'),
            'request_checksum_calculation' => 'when_required',
        ];
        $this->internalClient = new S3Client($this->withEndpoint(
            $base,
            config('filesystems.disks.s3.endpoint'),
        ));
        $this->signingClient = new S3Client($this->withEndpoint(
            $base,
            config('media.upload.public_endpoint'),
        ));
    }

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        $command = $this->signingClient->getCommand('PutObject', [
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
        ]);
        $unsignedRequest = \Aws\serialize($command)->withHeader('If-None-Match', '*');
        $request = (new WriteOnceS3SignatureV4('s3', (string) config('filesystems.disks.s3.region')))
            ->presign(
                $unsignedRequest,
                $this->signingClient->getCredentials()->wait(),
                $expiresAt,
            );
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) !== 'host') {
                $headers[$name] = implode(', ', $values);
            }
        }

        return new UploadAuthorization(
            (string) $request->getUri(),
            $headers,
            CarbonImmutable::instance($expiresAt),
        );
    }

    public function inspect(string $key): ?StoredObject
    {
        try {
            $result = $this->internalClient->headObject([
                'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                'Key' => $key,
            ]);
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404 || $exception->getAwsErrorCode() === 'NotFound') {
                return null;
            }

            throw $exception;
        }

        return new StoredObject((int) $result['ContentLength']);
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
