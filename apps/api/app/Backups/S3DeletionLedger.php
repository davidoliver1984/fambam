<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class S3DeletionLedger implements DeletionLedger
{
    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('s3');
    }

    public function record(string $familySpaceId, int $actorUserId, CarbonImmutable $completedAt): void
    {
        if (! Str::isUlid($familySpaceId) || $actorUserId < 1) {
            throw new RuntimeException('Deletion ledger identity is invalid.');
        }
        $key = $this->key($familySpaceId);
        if ($this->disk->exists($key)) {
            $existing = $this->decode($this->read($key));
            if ($existing['family_space_id'] !== $familySpaceId || $existing['actor_user_id'] !== $actorUserId) {
                throw new RuntimeException('Existing deletion ledger identity does not match the completed deletion.');
            }

            return;
        }
        $payload = json_encode([
            'schema_version' => 1,
            'family_space_id' => $familySpaceId,
            'actor_user_id' => $actorUserId,
            'completed_at' => $completedAt->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! $this->disk->put($key, $payload)) {
            throw new RuntimeException('Deletion ledger record could not be persisted.');
        }
    }

    public function completedAfter(CarbonImmutable $snapshotAt): array
    {
        $entries = [];
        foreach ($this->disk->files($this->prefix()) as $key) {
            $entry = $this->decode($this->read($key));
            if ($entry['completed_at']->isAfter($snapshotAt)) {
                $entries[] = $entry;
            }
        }
        usort($entries, function (array $left, array $right): int {
            $timestampOrder = $left['completed_at']->getTimestamp() - $right['completed_at']->getTimestamp();

            return $timestampOrder !== 0
                ? $timestampOrder
                : strcmp($left['family_space_id'], $right['family_space_id']);
        });

        return $entries;
    }

    private function key(string $familySpaceId): string
    {
        return $this->prefix()."/{$familySpaceId}.json";
    }

    private function prefix(): string
    {
        $prefix = trim((string) config('backup.deletion_ledger_prefix'), '/');
        if ($prefix === '' || str_contains($prefix, '..')) {
            throw new RuntimeException('Deletion ledger prefix is invalid.');
        }

        return $prefix;
    }

    private function read(string $key): string
    {
        $contents = $this->disk->get($key);
        if (! is_string($contents)) {
            throw new RuntimeException('Deletion ledger record could not be read.');
        }

        return $contents;
    }

    /** @return array{family_space_id: string, actor_user_id: int, completed_at: CarbonImmutable} */
    private function decode(string $payload): array
    {
        try {
            $entry = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Deletion ledger record is invalid.', previous: $exception);
        }
        if (! is_array($entry)
            || ($entry['schema_version'] ?? null) !== 1
            || ! is_string($entry['family_space_id'] ?? null)
            || ! Str::isUlid($entry['family_space_id'])
            || ! is_int($entry['actor_user_id'] ?? null)
            || $entry['actor_user_id'] < 1
            || ! is_string($entry['completed_at'] ?? null)) {
            throw new RuntimeException('Deletion ledger record has an invalid shape.');
        }
        try {
            $completedAt = CarbonImmutable::parse($entry['completed_at']);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Deletion ledger completion timestamp is invalid.', previous: $exception);
        }

        return [
            'family_space_id' => $entry['family_space_id'],
            'actor_user_id' => $entry['actor_user_id'],
            'completed_at' => $completedAt,
        ];
    }
}
