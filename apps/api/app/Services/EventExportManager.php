<?php

namespace App\Services;

use App\Enums\EventExportState;
use App\Jobs\GenerateEventExport;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Models\EventExport;
use App\Models\FamilyEvent;
use App\Models\Photo;
use App\Models\User;
use App\Storage\FamilyStorageKey;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class EventExportManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly MediaDeliveryUrlSigner $signer,
        private readonly AuditRecorder $audit,
        private readonly DatabaseTenantContext $databaseContext,
    ) {}

    public function request(FamilyEvent $event, User $actor, Request $request): EventExport
    {
        $context = TenantOperationContext::fromRequest($event->familySpace, $actor, $request);

        $export = DB::transaction(function () use ($event, $actor, $request, $context): EventExport {
            $export = new EventExport([
                'family_space_id' => $event->family_space_id,
                'event_id' => $event->id,
                'requested_by' => $actor->id,
                'state' => EventExportState::Pending,
            ]);
            $export->id = (string) Str::ulid();
            $export->object_key = FamilyStorageKey::for(
                $event->family_space_id,
                "event-exports/{$event->id}/{$export->id}.zip",
            );
            $export->save();
            $this->audit->record('event_export.requested', $export, $actor, $request, operationContext: $context);

            return $export;
        });

        GenerateEventExport::dispatch($context->toArray(), $export->id);

        return $export;
    }

    /** @return Collection<int, EventExport> */
    public function all(FamilyEvent $event): Collection
    {
        return EventExport::query()->with('requester:id,name')
            ->where('event_id', $event->id)->latest()->get();
    }

    public function find(FamilyEvent $event, string $id): EventExport
    {
        return EventExport::query()->with('requester:id,name')
            ->where('event_id', $event->id)->findOrFail($id);
    }

    public function authorizeDownload(EventExport $export, User $actor, Request $request): MediaDeliveryAuthorization
    {
        if ($export->state !== EventExportState::Ready
            || $export->expires_at === null || ! $export->expires_at->isFuture()) {
            throw ValidationException::withMessages(['export' => ['This Event archive is not available.']]);
        }

        $authorization = $this->signer->authorizeRead(
            $export->object_key,
            'application/zip',
            now()->addMinutes((int) config('events.export_download_ttl_minutes')),
            MediaSigningAudience::Browser,
        );
        $this->audit->record('event_export.download_authorised', $export, $actor, $request, [
            'authorization_expires_at' => $authorization->expiresAt->toAtomString(),
        ]);

        return $authorization;
    }

    public function generate(TenantOperationContext $context, string $exportId): void
    {
        $export = $this->claim($context, $exportId);
        if ($export === null) {
            return;
        }

        $directory = sys_get_temp_dir().'/fambam-event-export-'.Str::uuid();
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('The Event export workspace could not be created.');
        }
        $zipPath = $directory.'/archive.zip';
        $sourcePaths = [];

        try {
            [$event, $photos] = $this->snapshot($context, $export);
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The Event archive could not be opened.');
            }

            $manifestPhotos = [];
            $archiveTimestamp = $export->created_at?->getTimestamp() ?? now()->getTimestamp();
            foreach ($photos as $photo) {
                $upload = $photo->mediaUpload;
                if ($upload === null || $upload->original_object_key === null || $upload->original_sha256 === null) {
                    throw new RuntimeException('An Event Photo has no preserved original.');
                }
                $extension = $this->extensionForMime((string) $upload->detected_mime_type);
                $sourcePath = $directory."/{$photo->id}.{$extension}";
                $this->storage->downloadTo($upload->original_object_key, $sourcePath);
                $sourcePaths[] = $sourcePath;
                $checksum = hash_file('sha256', $sourcePath);
                if ($checksum === false || ! hash_equals($upload->original_sha256, $checksum)) {
                    throw new RuntimeException('A preserved original failed its Event export integrity check.');
                }
                $entry = "originals/{$photo->id}.{$extension}";
                if (! $zip->addFile($sourcePath, $entry)) {
                    throw new RuntimeException('A preserved original could not be added to the Event archive.');
                }
                $zip->setMtimeName($entry, $archiveTimestamp);
                $manifestPhotos[] = $this->photoManifest($photo, $entry, $event->id);
            }

            $manifest = json_encode([
                'schema_version' => 1,
                'generated_at' => $export->created_at?->toAtomString(),
                'requested_by' => ['id' => $export->requested_by, 'name' => $export->requester->name],
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'starts_on' => $event->starts_on?->format('Y-m-d'),
                    'ends_on' => $event->ends_on?->format('Y-m-d'),
                    'location' => $event->location,
                    'status' => $event->status->value,
                ],
                'photos' => $manifestPhotos,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! $zip->addFromString('manifest.json', $manifest)) {
                throw new RuntimeException('The Event manifest could not be added to the archive.');
            }
            $zip->setMtimeName('manifest.json', $archiveTimestamp);
            if (! $zip->close()) {
                throw new RuntimeException('The Event archive could not be finalised.');
            }

            $archiveSha256 = hash_file('sha256', $zipPath);
            $byteSize = filesize($zipPath);
            if ($archiveSha256 === false || $byteSize === false) {
                throw new RuntimeException('The Event archive integrity metadata could not be calculated.');
            }
            $this->storage->finalizeWriteOnce($zipPath, $export->object_key, $archiveSha256);
            $this->markReady($context, $export->id, $archiveSha256, $byteSize, count($manifestPhotos));
        } finally {
            foreach ($sourcePaths as $sourcePath) {
                @unlink($sourcePath);
            }
            @unlink($zipPath);
            @rmdir($directory);
        }
    }

    public function markFailed(TenantOperationContext $context, string $exportId): void
    {
        DB::transaction(function () use ($context, $exportId): void {
            $this->establish($context);
            $export = EventExport::query()->lockForUpdate()->find($exportId);
            if ($export === null || $export->state === EventExportState::Ready
                || $export->state === EventExportState::Expired) {
                return;
            }
            $export->update(['state' => EventExportState::Failed, 'failure_reason' => 'generation_failed']);
            $this->audit->record('event_export.failed', $export, metadata: [
                'reason' => 'generation_failed',
            ], operationContext: $context);
        });
    }

    public function expire(TenantOperationContext $context, string $exportId): void
    {
        $export = DB::transaction(function () use ($context, $exportId): ?EventExport {
            $this->establish($context);
            $export = EventExport::query()->lockForUpdate()->find($exportId);
            if ($export === null || $export->state !== EventExportState::Ready
                || $export->expires_at === null || $export->expires_at->isFuture()) {
                return null;
            }

            return $export;
        });
        if ($export === null) {
            return;
        }

        $this->storage->delete($export->object_key);
        DB::transaction(function () use ($context, $exportId): void {
            $this->establish($context);
            $export = EventExport::query()->lockForUpdate()->find($exportId);
            if ($export === null || $export->state !== EventExportState::Ready
                || $export->expires_at === null || $export->expires_at->isFuture()) {
                return;
            }
            $export->update(['state' => EventExportState::Expired]);
            $this->audit->record('event_export.expired', $export, operationContext: $context);
        });
    }

    private function claim(TenantOperationContext $context, string $exportId): ?EventExport
    {
        return DB::transaction(function () use ($context, $exportId): ?EventExport {
            $this->establish($context);
            $export = EventExport::query()->lockForUpdate()->find($exportId);
            if ($export === null || ! in_array($export->state, [
                EventExportState::Pending, EventExportState::Processing, EventExportState::Failed,
            ], true)) {
                return null;
            }
            $export->update(['state' => EventExportState::Processing, 'failure_reason' => null]);

            return $export;
        });
    }

    /** @return array{FamilyEvent, Collection<int, Photo>} */
    private function snapshot(TenantOperationContext $context, EventExport $export): array
    {
        return DB::transaction(function () use ($context, $export): array {
            $this->establish($context);
            $event = FamilyEvent::query()->findOrFail($export->event_id);
            $export->loadMissing('requester:id,name');
            $photos = Photo::query()
                ->where('family_space_id', $export->family_space_id)
                ->where(fn ($query) => $query->where('primary_event_id', $event->id)
                    ->orWhereHas('albums', fn ($album) => $album->where('albums.event_id', $event->id)))
                ->with([
                    'creator:id,name', 'mediaUpload.uploader:id,name', 'tags:id,label',
                    'albums:id,event_id',
                    'photographer:id,preferred_name', 'scanner:id,preferred_name',
                    'physicalOwner:id,preferred_name',
                ])->orderBy('id')->get();

            return [$event, $photos];
        });
    }

    /** @return array<string, mixed> */
    private function photoManifest(Photo $photo, string $entry, string $eventId): array
    {
        $upload = $photo->mediaUpload;

        return [
            'photo_id' => $photo->id,
            'media_upload_id' => $photo->media_upload_id,
            'archive_entry' => $entry,
            'original_filename' => $upload?->client_filename,
            'upload_method' => $upload?->upload_method,
            'upload_batch_id' => $upload?->upload_batch_id,
            'detected_mime_type' => $upload?->detected_mime_type,
            'byte_size' => $upload?->byte_size,
            'sha256' => $upload?->original_sha256,
            'uploader' => $upload?->uploader === null ? null : [
                'id' => $upload->uploader->id, 'name' => $upload->uploader->name,
            ],
            'creator' => $photo->creator === null ? null : [
                'id' => $photo->creator->id, 'name' => $photo->creator->name,
            ],
            'caption' => $photo->caption,
            'description' => $photo->description,
            'historical_date' => [
                'precision' => $photo->historical_date_precision?->value,
                'value' => $photo->historical_date?->format('Y-m-d'),
            ],
            'location' => $photo->location_description,
            'primary_event_id' => $photo->primary_event_id,
            'event_album_ids' => $photo->albums->where('event_id', $eventId)->pluck('id')->sort()->values()->all(),
            'tags' => $photo->tags->pluck('label')->sort()->values()->all(),
            'provenance' => [
                'archive_source_description' => $photo->archive_source_description,
                'photographer' => $this->personClaim($photo->photographer, $photo->photographer_description),
                'scanner' => $this->personClaim($photo->scanner, $photo->scanner_description),
                'physical_owner' => $this->personClaim($photo->physicalOwner, $photo->physical_source_description),
            ],
        ];
    }

    /** @return array{id: string, name: string}|array{description: string}|null */
    private function personClaim(?object $person, ?string $description): ?array
    {
        if ($person !== null) {
            return ['id' => (string) $person->id, 'name' => (string) $person->preferred_name];
        }

        return $description === null ? null : ['description' => $description];
    }

    private function extensionForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/webp' => 'webp',
            'image/tiff' => 'tif',
            default => throw new RuntimeException('An Event Photo has an unsupported detected format.'),
        };
    }

    private function markReady(
        TenantOperationContext $context,
        string $exportId,
        string $sha256,
        int $byteSize,
        int $photoCount,
    ): void {
        DB::transaction(function () use ($context, $exportId, $sha256, $byteSize, $photoCount): void {
            $this->establish($context);
            $export = EventExport::query()->lockForUpdate()->find($exportId);
            if ($export === null || $export->state !== EventExportState::Processing) {
                return;
            }
            $export->update([
                'state' => EventExportState::Ready,
                'archive_sha256' => $sha256,
                'byte_size' => $byteSize,
                'photo_count' => $photoCount,
                'expires_at' => now()->addHours((int) config('events.export_lifetime_hours')),
            ]);
            $this->audit->record('event_export.ready', $export, metadata: [
                'photo_count' => $photoCount, 'byte_size' => $byteSize, 'sha256' => $sha256,
            ], operationContext: $context);
        });
    }

    private function establish(TenantOperationContext $context): void
    {
        $this->databaseContext->establishUser($context->actorUserId);
        $this->databaseContext->establishFamilySpace($context->familySpaceId);
    }
}
