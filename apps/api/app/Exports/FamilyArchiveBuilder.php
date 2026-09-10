<?php

namespace App\Exports;

use App\Enums\FamilyExportScope;
use App\Enums\MediaUploadState;
use App\Enums\PersonProposalStatus;
use App\Media\MediaObjectStorage;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilyExport;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonRelationship;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoPerson;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Models\SavedSearch;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class FamilyArchiveBuilder
{
    private const SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const DOMAIN_FILES = [
        'people.json', 'relationships.json', 'photos.json', 'albums.json',
        'events.json', 'stories.json', 'comments.json', 'reactions.json',
        'saved_searches.json',
    ];

    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly DatabaseTenantContext $databaseContext,
        private readonly TenantContext $tenantContext,
    ) {}

    public function buildAndStore(
        TenantOperationContext $context,
        FamilyExport $export,
        User $requester,
        FamilyExportSelection $selection,
    ): BuiltFamilyArchive {
        $directory = sys_get_temp_dir().'/fambam-family-export-'.Str::uuid();
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('The family export workspace could not be created.');
        }

        try {
            $originals = $this->copyPreliminaryOriginals($directory, $context, $export, $requester, $selection);
            [$domains, $originals, $unattached, $requesterAccountLink] = $this->reconcileAndSnapshot(
                $directory,
                $context,
                $export,
                $requester,
                $selection,
                $originals,
            );
            $this->writeDomainFiles($directory, $domains);
            $this->writeManifest($directory, $export, $requester, $domains, $originals, $unattached, $requesterAccountLink);
            $checksums = $this->checksums($directory, [...array_values($originals), ...array_values($unattached)]);
            $this->writeJson($directory.'/checksums.json', [
                'schema_version' => self::SCHEMA_VERSION,
                'files' => $checksums,
            ]);
            $zipPath = $this->seal($directory, $export, [...array_values($originals), ...array_values($unattached)]);
            $sha256 = hash_file('sha256', $zipPath);
            $byteSize = filesize($zipPath);
            if ($sha256 === false || $byteSize === false) {
                throw new RuntimeException('The family archive integrity metadata could not be calculated.');
            }
            $this->storage->finalizeWriteOnce($zipPath, $export->object_key, $sha256);

            return new BuiltFamilyArchive($sha256, $byteSize, count($domains['photos.json']));
        } finally {
            $this->removeDirectory($directory);
            $this->tenantContext->clear();
        }
    }

    /**
     * @return array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>
     */
    private function copyPreliminaryOriginals(
        string $directory,
        TenantOperationContext $context,
        FamilyExport $export,
        User $requester,
        FamilyExportSelection $selection,
    ): array {
        $photos = DB::transaction(function () use ($context, $export, $requester, $selection): Collection {
            $this->establish($context, $requester);
            $query = $export->scope === FamilyExportScope::FamilySpaceFull ? Photo::withTrashed() : Photo::query();

            return $query->whereIn('id', $selection->originalPhotoIds)->with('mediaUpload')->orderBy('id')->get();
        });
        $originals = [];
        foreach ($photos as $photo) {
            if ($photo->mediaUpload !== null) {
                $originals[$photo->id] = $this->copyOriginal($directory, $photo->mediaUpload, "media/originals/{$photo->id}");
            }
        }

        return $originals;
    }

    /**
     * @param  array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>  $originals
     * @return array{array<string, list<array<string, mixed>>>, array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>, array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>, array{id:string,person_id:string}|null}
     */
    private function reconcileAndSnapshot(
        string $directory,
        TenantOperationContext $context,
        FamilyExport $export,
        User $requester,
        FamilyExportSelection $selection,
        array $originals,
    ): array {
        return DB::transaction(function () use ($directory, $context, $export, $requester, $selection, $originals): array {
            [$family, $membership] = $this->establish($context, $requester);
            $photoQuery = $export->scope === FamilyExportScope::FamilySpaceFull ? Photo::withTrashed() : Photo::query();
            $selectedPhotoIds = collect([...$selection->photoIds, ...$selection->contextPhotoIds])
                ->unique()->sort()->values()->all();
            /** @var Collection<int, Photo> $photos */
            $photos = $photoQuery->where('family_space_id', $family->id)
                ->whereIn('id', $selectedPhotoIds)
                ->with(['mediaUpload', 'tags:id,label', 'albums', 'photoPeople' => fn ($query) => $query
                    ->where('status', PersonProposalStatus::Approved)->orderBy('person_id')])
                ->orderBy('id')->get();

            if ($export->scope === FamilyExportScope::Personal) {
                $photos = $photos->filter(fn (Photo $photo): bool => Gate::forUser($requester)->allows('view', $photo))->values();
            }
            $survivingPhotoIds = $photos->pluck('id')->all();

            foreach ($photos as $photo) {
                if ($export->scope === FamilyExportScope::FamilySpaceFull && $photo->mediaUpload === null) {
                    throw new RuntimeException('A full family export Photo has no preserved original.');
                }
                $mayInclude = $export->scope === FamilyExportScope::FamilySpaceFull
                    || ($photo->mediaUpload !== null && Gate::forUser($requester)->allows('downloadOriginal', $photo->mediaUpload));
                if ($mayInclude && ! isset($originals[$photo->id]) && $photo->mediaUpload !== null) {
                    $originals[$photo->id] = $this->copyOriginal($directory, $photo->mediaUpload, "media/originals/{$photo->id}");
                }
                if (! $mayInclude && isset($originals[$photo->id])) {
                    @unlink($originals[$photo->id]['path']);
                    unset($originals[$photo->id]);
                }
            }
            foreach (array_diff(array_keys($originals), $survivingPhotoIds) as $removedPhotoId) {
                @unlink($originals[$removedPhotoId]['path']);
                unset($originals[$removedPhotoId]);
            }

            $domains = $this->domains($export, $requester, $selection, $photos, $survivingPhotoIds, $originals);
            $unattached = [];
            if ($export->scope === FamilyExportScope::FamilySpaceFull) {
                $uploads = MediaUpload::query()->whereIn('id', $selection->unattachedMediaUploadIds)
                    ->whereIn('state', [
                        MediaUploadState::Preserved,
                        MediaUploadState::Processing,
                        MediaUploadState::Ready,
                        MediaUploadState::Degraded,
                    ])
                    ->whereNotNull('original_object_key')->whereNotNull('original_sha256')
                    ->whereDoesntHave('photo')->orderBy('id')->get();
                foreach ($uploads as $upload) {
                    $unattached[$upload->id] = $this->copyOriginal($directory, $upload, "media/unattached/{$upload->id}");
                }
            }

            $requesterAccountLink = PersonAccountLink::query()->where('user_id', $requester->id)->first();

            return [$domains, $originals, $unattached, $requesterAccountLink === null ? null : [
                'id' => $requesterAccountLink->id,
                'person_id' => $requesterAccountLink->person_id,
            ]];
        });
    }

    /**
     * @param  Collection<int, Photo>  $photos
     * @param  list<string>  $photoIds
     * @param  array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>  $originals
     * @return array<string, list<array<string, mixed>>>
     */
    private function domains(FamilyExport $export, User $requester, FamilyExportSelection $selection, Collection $photos, array $photoIds, array $originals): array
    {
        $full = $export->scope === FamilyExportScope::FamilySpaceFull;
        $personIds = $selection->personIds;
        if (! $full) {
            $personIds = PhotoPerson::query()->whereIn('photo_id', $photoIds)
                ->where('status', PersonProposalStatus::Approved)->pluck('person_id')->unique()->sort()->values()->all();
        }
        $peopleQuery = $full ? Person::withTrashed() : Person::query();
        $people = $peopleQuery->whereIn('id', $personIds)->orderBy('id')->get();
        $accountLinks = PersonAccountLink::query()->whereIn('person_id', $people->pluck('id'))
            ->when(! $full, fn ($query) => $query->where('user_id', $export->requested_by))
            ->with('user:id,name')->orderBy('id')->get()->keyBy('person_id');
        $relationships = PersonRelationship::query()->where('status', 'confirmed')
            ->whereIn('subject_person_id', $people->pluck('id'))->whereIn('related_person_id', $people->pluck('id'))
            ->orderBy('id')->get();
        $albums = Album::query()->whereIn('id', $selection->albumIds)
            ->with(['albumPhotos' => fn ($query) => $query->whereIn('photo_id', $photoIds)->orderBy('position')])
            ->orderBy('id')->get();
        $eventQuery = $full ? FamilyEvent::withTrashed() : FamilyEvent::query();
        $events = $eventQuery->whereIn('id', $selection->eventIds)->orderBy('id')->get();
        $storyQuery = $full ? PhotoStory::withTrashed() : PhotoStory::query();
        $stories = $storyQuery->whereIn('id', $selection->storyIds)->whereIn('photo_id', $photoIds)->orderBy('id')->get();
        $commentQuery = $full ? PhotoComment::withTrashed() : PhotoComment::query();
        $comments = $commentQuery->whereIn('id', $selection->commentIds)->whereIn('photo_id', $photoIds)->orderBy('id')->get();
        $reactions = PhotoReaction::query()->whereIn('id', $selection->reactionIds)->whereIn('photo_id', $photoIds)->orderBy('id')->get();
        $savedSearches = SavedSearch::query()->whereIn('id', $selection->savedSearchIds)->with('people:id')->orderBy('id')->get();

        $photoRows = $photos->map(function (Photo $photo) use ($export, $originals, $requester): array {
            $row = $this->only($photo, [
                'id', 'media_upload_id', 'created_by', 'visibility', 'caption', 'description', 'archive_source_description',
                'photographer_person_id', 'photographer_description', 'scanner_person_id', 'scanner_description',
                'physical_owner_person_id', 'physical_source_description', 'historical_date_precision',
                'historical_date', 'location_description', 'primary_event_id', 'do_not_resurface',
                'created_at', 'updated_at', 'deleted_at',
            ]);
            $row['tags'] = $photo->tags->sortBy('id')->values()->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'label' => $tag->label,
            ])->all();
            $row['approved_people'] = $photo->photoPeople->map(fn (PhotoPerson $association) => $this->only($association, [
                'id', 'person_id', 'proposal_source', 'proposed_by', 'resolved_by', 'resolved_at',
            ]))->all();
            $row['album_references'] = $photo->albums->filter(fn (Album $album): bool => $export->scope === FamilyExportScope::FamilySpaceFull
                || Gate::forUser($requester)->allows('view', $album))->sortBy('id')->values()->map(fn (Album $album) => [
                    'album_id' => $album->id,
                    'event_id' => $album->event_id,
                ])->all();
            $row['original_included'] = isset($originals[$photo->id]);
            if (isset($originals[$photo->id])) {
                $original = $originals[$photo->id];
                $row['original_path'] = $original['archive_path'];
                $row['original_sha256'] = $original['sha256'];
                $row['original_media'] = $this->only($original['upload'], [
                    'client_filename', 'detected_mime_type', 'byte_size', 'pixel_width', 'pixel_height',
                    'original_orientation', 'camera_make', 'camera_model', 'exif_capture_timestamp',
                ]);
            }

            return $row;
        })->all();

        return [
            'people.json' => $people->map(function (Person $person) use ($accountLinks): array {
                $row = $this->only($person, [
                    'id', 'preferred_name', 'alternate_names', 'identity_status', 'birth_date', 'birth_date_precision',
                    'is_deceased', 'death_date', 'death_date_precision', 'biography', 'confirmed_at',
                    'created_at', 'updated_at', 'deleted_at',
                ]);
                $link = $accountLinks->get($person->id);
                if ($link !== null) {
                    $row['account_link'] = ['id' => $link->id, 'name' => $link->user?->name];
                }

                return $row;
            })->all(),
            'relationships.json' => $relationships->map(fn (PersonRelationship $relationship) => $this->only($relationship, [
                'id', 'subject_person_id', 'related_person_id', 'type', 'status', 'context', 'created_at', 'updated_at',
            ]))->all(),
            'photos.json' => $photoRows,
            'albums.json' => $albums->map(function (Album $album): array {
                $row = $this->only($album, ['id', 'created_by', 'name', 'description', 'visibility', 'event_id', 'guest_participation', 'created_at', 'updated_at']);
                $row['photos'] = $album->albumPhotos->map(fn ($link) => $this->only($link, ['id', 'photo_id', 'position', 'added_by', 'created_at']))->all();

                return $row;
            })->all(),
            'events.json' => $events->map(fn (FamilyEvent $event) => $this->only($event, ['id', 'created_by', 'name', 'description', 'starts_on', 'ends_on', 'location', 'status', 'created_at', 'updated_at', 'deleted_at']))->all(),
            'stories.json' => $stories->map(fn (PhotoStory $story) => $this->only($story, ['id', 'photo_id', 'author_id', 'body', 'edited_at', 'created_at', 'updated_at', 'deleted_at']))->all(),
            'comments.json' => $comments->map(fn (PhotoComment $comment) => $this->only($comment, ['id', 'photo_id', 'album_id', 'author_id', 'body', 'edited_at', 'created_at', 'updated_at', 'deleted_at']))->all(),
            'reactions.json' => $reactions->map(fn (PhotoReaction $reaction) => $this->only($reaction, ['id', 'photo_id', 'album_id', 'user_id', 'reaction', 'created_at', 'updated_at']))->all(),
            'saved_searches.json' => $savedSearches->map(function (SavedSearch $search) use ($people): array {
                $row = $this->only($search, ['id', 'created_by', 'name', 'filters', 'created_at', 'updated_at']);
                $included = $people->pluck('id');
                $row['people'] = DB::table('saved_search_people')->where('saved_search_id', $search->id)
                    ->orderBy('person_id')->pluck('person_id')->map(fn ($personId) => [
                        'person_id' => (string) $personId,
                        'resolved' => $included->contains((string) $personId),
                    ])->all();

                return $row;
            })->all(),
        ];
    }

    /** @return array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload} */
    private function copyOriginal(string $directory, MediaUpload $upload, string $archiveStem): array
    {
        if ($upload->original_object_key === null || $upload->original_sha256 === null) {
            throw new RuntimeException('An exported media item has no preserved original.');
        }
        $extension = $this->extensionForMime((string) $upload->detected_mime_type);
        $archivePath = "{$archiveStem}.{$extension}";
        $path = $directory.'/.media-'.hash('sha256', $archivePath);
        $this->storage->downloadTo($upload->original_object_key, $path);
        $checksum = hash_file('sha256', $path);
        $byteSize = filesize($path);
        if ($checksum === false || $byteSize === false || ! hash_equals($upload->original_sha256, $checksum)) {
            @unlink($path);
            throw new RuntimeException('A preserved original failed its family export integrity check.');
        }

        return ['path' => $path, 'archive_path' => $archivePath, 'sha256' => $checksum, 'byte_size' => $byteSize, 'upload' => $upload];
    }

    /** @param array<string, list<array<string, mixed>>> $domains */
    private function writeDomainFiles(string $directory, array $domains): void
    {
        foreach (self::DOMAIN_FILES as $filename) {
            $this->writeJson($directory.'/'.$filename, [
                'schema_version' => self::SCHEMA_VERSION,
                'items' => $domains[$filename],
            ]);
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $domains
     * @param  array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>  $originals
     * @param  array<string, array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>  $unattached
     * @param  array{id:string,person_id:string}|null  $requesterAccountLink
     */
    private function writeManifest(
        string $directory,
        FamilyExport $export,
        User $requester,
        array $domains,
        array $originals,
        array $unattached,
        ?array $requesterAccountLink,
    ): void {
        $family = $this->tenantContext->familySpace();
        $this->writeJson($directory.'/manifest.json', [
            'schema_version' => self::SCHEMA_VERSION,
            'export_scope' => $export->scope->value,
            'generated_at' => now()->toAtomString(),
            'family_space' => ['id' => $family->id, 'name' => $family->name, 'slug' => $family->slug],
            'requester' => [
                'id' => $requester->id,
                'name' => $requester->name,
                'timezone' => $requester->timezone,
                'person_account_link' => $requesterAccountLink,
            ],
            'record_counts' => collect($domains)->mapWithKeys(fn (array $items, string $file) => [str_replace('.json', '', $file) => count($items)])->all(),
            'media_byte_count' => collect([...array_values($originals), ...array_values($unattached)])->sum('byte_size'),
            'unattached_media' => collect($unattached)->map(fn (array $file, string $id) => [
                'media_upload_id' => $id,
                'path' => $file['archive_path'],
                'sha256' => $file['sha256'],
                'byte_size' => $file['byte_size'],
                'client_filename' => $file['upload']->client_filename,
                'detected_mime_type' => $file['upload']->detected_mime_type,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  list<array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}>  $media
     * @return array<string, string>
     */
    private function checksums(string $directory, array $media): array
    {
        $checksums = [];
        foreach (glob($directory.'/*.json') ?: [] as $path) {
            if (basename($path) !== 'checksums.json') {
                $checksum = hash_file('sha256', $path);
                if ($checksum === false) {
                    throw new RuntimeException('An export file could not be checksummed.');
                }
                $checksums[basename($path)] = $checksum;
            }
        }
        foreach ($media as $file) {
            $checksums[$file['archive_path']] = $file['sha256'];
        }
        ksort($checksums);

        return $checksums;
    }

    /** @param list<array{path:string,archive_path:string,sha256:string,byte_size:int,upload:MediaUpload}> $media */
    private function seal(string $directory, FamilyExport $export, array $media): string
    {
        $zipPath = $directory.'/archive.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The family archive could not be opened.');
        }
        $paths = glob($directory.'/*') ?: [];
        sort($paths);
        $timestamp = $export->created_at?->getTimestamp() ?? now()->getTimestamp();
        foreach ($paths as $path) {
            if ($path === $zipPath) {
                continue;
            }
            $name = basename($path);
            if (! $zip->addFile($path, $name)) {
                throw new RuntimeException('A family archive file could not be added.');
            }
            $zip->setMtimeName($name, $timestamp);
        }
        foreach ($media as $file) {
            if (! $zip->addFile($file['path'], $file['archive_path'])) {
                throw new RuntimeException('An exported original could not be added to the family archive.');
            }
            $zip->setMtimeName($file['archive_path'], $timestamp);
        }
        if (! $zip->close()) {
            throw new RuntimeException('The family archive could not be sealed.');
        }

        return $zipPath;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('An export manifest file could not be written.');
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function only(Model $model, array $fields): array
    {
        return array_intersect_key($model->attributesToArray(), array_flip($fields));
    }

    /** @return array{FamilySpace, FamilySpaceMembership} */
    private function establish(TenantOperationContext $context, User $requester): array
    {
        $this->databaseContext->establishUser($context->actorUserId);
        $this->databaseContext->establishFamilySpace($context->familySpaceId);
        $family = FamilySpace::query()->findOrFail($context->familySpaceId);
        $membership = FamilySpaceMembership::query()->where('family_space_id', $family->id)
            ->where('user_id', $requester->id)->where('state', 'active')->firstOrFail();
        $this->tenantContext->establish($family, $membership, $requester);

        return [$family, $membership];
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/tiff' => 'tiff', 'image/heic' => 'heic', 'image/heif' => 'heif',
            default => throw new RuntimeException('An exported original has an unsupported detected format.'),
        };
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
