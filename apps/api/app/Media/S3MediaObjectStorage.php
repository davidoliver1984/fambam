<?php

namespace App\Media;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;

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

        $metadata = $result['Metadata'] ?? [];

        return new StoredObject(
            (int) $result['ContentLength'],
            is_array($metadata) && is_string($metadata['sha256'] ?? null)
                ? $metadata['sha256']
                : null,
        );
    }

    public function downloadTo(string $key, string $localPath): void
    {
        $this->internalClient->getObject([
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
            'SaveAs' => $localPath,
        ]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The validated media file could not be opened.');
        }

        try {
            $command = $this->internalClient->getCommand('PutObject', [
                'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                'Key' => $key,
                'Body' => $stream,
                'ContentLength' => filesize($localPath),
                'Metadata' => ['sha256' => $sha256],
            ]);
            $unsignedRequest = \Aws\serialize($command)->withHeader('If-None-Match', '*');
            $request = (new WriteOnceS3SignatureV4('s3', (string) config('filesystems.disks.s3.region')))
                ->signRequest($unsignedRequest, $this->internalClient->getCredentials()->wait());
            (new Client)->send($request);
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 412) {
                throw $exception;
            }

            $existing = $this->inspect($key);
            if ($existing === null
                || $existing->byteSize !== filesize($localPath)
                || ! hash_equals($sha256, $existing->sha256 ?? '')) {
                throw new MediaObjectCollision('An immutable media object already exists at the final key.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function delete(string $key): void
    {
        $this->internalClient->deleteObject([
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
        ]);
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
