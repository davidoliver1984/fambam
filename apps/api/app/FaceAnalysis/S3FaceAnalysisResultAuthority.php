<?php

namespace App\FaceAnalysis;

use App\Media\UploadAuthorization;
use App\Media\WriteOnceS3SignatureV4;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class S3FaceAnalysisResultAuthority implements FaceAnalysisResultAuthority
{
    private S3Client $client;

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
        $endpoint = config('filesystems.disks.s3.endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $configuration['endpoint'] = $endpoint;
        }
        $this->client = new S3Client($configuration);
    }

    public function authorizeWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        $command = $this->client->getCommand('PutObject', [
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
            'Key' => $key,
            'Tagging' => 'fambam-retention=face-analysis-transient',
        ]);
        $unsigned = \Aws\serialize($command)->withHeader('If-None-Match', '*');
        $request = (new WriteOnceS3SignatureV4('s3', (string) config('filesystems.disks.s3.region')))
            ->presign($unsigned, $this->client->getCredentials()->wait(), $expiresAt);
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) !== 'host') {
                $headers[$name] = implode(', ', $values);
            }
        }

        return new UploadAuthorization((string) $request->getUri(), $headers, CarbonImmutable::instance($expiresAt));
    }
}
