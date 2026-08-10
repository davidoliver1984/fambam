<?php

namespace App\Media;

use App\Storage\FamilyStorageKey;
use Aws\S3\S3Client;

class S3FamilyMediaStorageCleaner implements FamilyMediaStorageCleaner
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
        $endpoint = config('filesystems.disks.s3.endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $configuration['endpoint'] = $endpoint;
        }

        $this->client = new S3Client($configuration);
    }

    public function deleteFamilyMedia(string $familySpaceId): void
    {
        foreach (['media-staging', 'media', 'quarantine'] as $area) {
            $this->deletePrefix(FamilyStorageKey::for($familySpaceId, $area).'/');
        }
    }

    private function deletePrefix(string $prefix): void
    {
        $bucket = (string) config('filesystems.disks.s3.bucket');
        $continuationToken = null;

        do {
            $request = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => (int) config('media.cleanup.storage_delete_page_size'),
            ];
            if ($continuationToken !== null) {
                $request['ContinuationToken'] = $continuationToken;
            }
            $result = $this->client->listObjectsV2($request);
            $objects = [];
            foreach ($result['Contents'] ?? [] as $object) {
                if (is_string($object['Key'] ?? null)) {
                    $objects[] = ['Key' => $object['Key']];
                }
            }
            if ($objects !== []) {
                $deletion = $this->client->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => ['Objects' => $objects, 'Quiet' => true],
                ]);
                if (($deletion['Errors'] ?? []) !== []) {
                    throw new \RuntimeException('Family media object cleanup was incomplete.');
                }
            }
            $continuationToken = ($result['IsTruncated'] ?? false) === true
                && is_string($result['NextContinuationToken'] ?? null)
                    ? $result['NextContinuationToken']
                    : null;
        } while ($continuationToken !== null);
    }
}
