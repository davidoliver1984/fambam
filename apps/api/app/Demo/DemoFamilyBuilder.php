<?php

namespace App\Demo;

use App\Enums\DatePrecision;
use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MediaUploadState;
use App\Enums\MembershipState;
use App\Media\FamilyMediaStorageCleaner;
use App\Media\MediaObjectStorage;
use App\Models\FamilySpace;
use App\Models\User;
use App\Storage\FamilyStorageKey;
use App\Stories\RichTextDocument;
use App\Stories\StoryWriter;
use App\Tenancy\DatabaseTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DemoFamilyBuilder
{
    public const OWNER_EMAIL = 'mercer.owner@fambam.test';

    public const ADMIN_EMAIL = 'mercer.admin@fambam.test';

    public const MEMBER_EMAIL = 'mercer.member@fambam.test';

    public const PASSWORD = 'Local-demo-only!2026';

    public function __construct(
        private readonly DemoFamilyGuard $guard,
        private readonly DemoArchiveImage $images,
        private readonly MediaObjectStorage $storage,
        private readonly FamilyMediaStorageCleaner $storageCleaner,
        private readonly DatabaseTenantContext $databaseTenantContext,
        private readonly RichTextDocument $documents,
        private readonly StoryWriter $storyWriter,
    ) {}

    /** @return array<string, int|string|bool> */
    public function seed(): array
    {
        $this->guard->assertEnabled();
        $users = $this->users();
        $existing = DB::transaction(function () use ($users): ?FamilySpace {
            $this->databaseTenantContext->establishUser($users['owner']);
            $family = FamilySpace::query()->where('slug', config('demo-family.slug'))->first();
            if ($family !== null) {
                return $family;
            }
            $candidateIds = DB::table('family_space_memberships')
                ->where('user_id', $users['owner']->id)->pluck('family_space_id');
            foreach ($candidateIds as $candidateId) {
                $this->databaseTenantContext->establishFamilySpace(
                    (string) $candidateId,
                    authoritativeOperation: 'deletion_teardown',
                );
                $family = FamilySpace::query()->where('slug', config('demo-family.slug'))->first();
                if ($family !== null) {
                    return $family;
                }
            }

            return null;
        });

        if ($existing !== null) {
            if ($existing->status === FamilySpaceStatus::Deleted) {
                try {
                    DB::transaction(function () use ($existing, $users): void {
                        $this->databaseTenantContext->establishUser($users['owner']);
                        $this->databaseTenantContext->establishFamilySpace(
                            $existing,
                            authoritativeOperation: 'deletion_teardown',
                        );
                        $this->populate($existing->id, $users, true);
                    }, 3);
                } catch (Throwable $exception) {
                    $this->storageCleaner->deleteFamilyMedia($existing->id);
                    throw $exception;
                }

                return $this->summary($existing, $users['owner']) + ['created' => true];
            }
            $summary = $this->summary($existing, $users['owner']);
            if ($this->isComplete($summary)) {
                return $summary + ['created' => false];
            }

            throw new RuntimeException('Mercer Family Demo is incomplete. Run fambam:demo-family:reset --force before reseeding.');
        }

        $familyId = (string) Str::ulid();
        try {
            DB::transaction(function () use ($familyId, $users): void {
                $this->databaseTenantContext->establishUser($users['owner']);
                $this->databaseTenantContext->establishFamilySpace($familyId, authoritativeOperation: 'family_space_creation');
                $this->populate($familyId, $users, false);
            }, 3);
        } catch (Throwable $exception) {
            $this->storageCleaner->deleteFamilyMedia($familyId);
            throw $exception;
        }

        $family = DB::transaction(function () use ($users): FamilySpace {
            $this->databaseTenantContext->establishUser($users['owner']);

            return FamilySpace::query()->where('slug', config('demo-family.slug'))->firstOrFail();
        });

        return $this->summary($family, $users['owner']) + ['created' => true];
    }

    /** @return array{owner: User, admin: User, member: User} */
    private function users(): array
    {
        $definitions = [
            'owner' => ['name' => 'David Mercer (Demo Owner)', 'email' => self::OWNER_EMAIL],
            'admin' => ['name' => 'Sarah Mercer (Demo Administrator)', 'email' => self::ADMIN_EMAIL],
            'member' => ['name' => 'Maya Mercer (Demo Member)', 'email' => self::MEMBER_EMAIL],
        ];
        $users = [];
        foreach ($definitions as $key => $definition) {
            $users[$key] = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                ['name' => $definition['name'], 'password' => self::PASSWORD, 'timezone' => 'Europe/London'],
            );
            $users[$key]->forceFill(['email_verified_at' => now(), 'revoked_at' => null])->saveQuietly();
        }

        /** @var array{owner: User, admin: User, member: User} $users */
        return $users;
    }

    /** @param array{owner: User, admin: User, member: User} $users */
    private function populate(string $familyId, array $users, bool $restore): void
    {
        $anchor = CarbonImmutable::now()->startOfDay();
        $familyCreated = $anchor->subDays(29);
        if ($restore) {
            DB::table('family_spaces')->where('id', $familyId)->update([
                'name' => config('demo-family.name'), 'status' => FamilySpaceStatus::Active->value,
                'deletion_requested_at' => null, 'deletion_requested_by' => null,
                'scheduled_deletion_at' => null, 'updated_at' => $familyCreated,
            ]);
        } else {
            $this->insert('family_spaces', [
                'id' => $familyId, 'slug' => config('demo-family.slug'),
                'name' => config('demo-family.name'), 'status' => FamilySpaceStatus::Active->value,
            ], $familyCreated);
        }

        foreach (['owner', 'admin', 'member'] as $index => $key) {
            $membership = [
                'family_space_id' => $familyId,
                'user_id' => $users[$key]->id,
                'role' => [FamilySpaceRole::Owner, FamilySpaceRole::Administrator, FamilySpaceRole::Member][$index]->value,
                'state' => MembershipState::Active->value,
                'removed_at' => null, 'removed_by' => null, 'updated_at' => $familyCreated->addDay(),
            ];
            if ($restore) {
                DB::table('family_space_memberships')->where('family_space_id', $familyId)
                    ->where('user_id', $users[$key]->id)->update($membership);
            } else {
                $this->insert('family_space_memberships', ['id' => (string) Str::ulid()] + $membership, $familyCreated->addDay());
            }
        }

        $people = $this->people($familyId, $users['owner'], $anchor);
        $this->relationships($familyId, $people, $users['owner'], $anchor);
        foreach (['owner' => 'david', 'admin' => 'sarah', 'member' => 'maya'] as $userKey => $personKey) {
            $this->insert('person_account_links', [
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId,
                'person_id' => $people[$personKey], 'user_id' => $users[$userKey]->id,
                'created_by' => $users['owner']->id,
            ], $anchor->subDays(26));
        }

        $events = $this->events($familyId, $users, $anchor);
        $albums = $this->albums($familyId, $events, $users, $anchor);
        $tags = $this->tags($familyId, $users['admin'], $anchor);
        $this->eventTags($familyId, $events, $tags, $users['admin'], $anchor);
        $photos = $this->photos($familyId, $people, $events, $albums, $tags, $users, $anchor);
        $this->conversations($familyId, $people, $events, $photos, $albums, $users, $anchor);
        $this->savedSearches($familyId, $people, $events, $albums, $users, $anchor);
    }

    /** @return array<string, string> */
    private function people(string $familyId, User $owner, CarbonImmutable $anchor): array
    {
        $definitions = [
            'william' => ['William Mercer', '1945-05-14', 'Family storyteller, railway enthusiast and keeper of the old photo boxes.'],
            'margaret' => ['Margaret Mercer', '1947-11-02', 'Known for generous Christmas tables and carefully labelled albums.'],
            'elaine' => ['Elaine Mercer', '1969-03-20', 'Eldest child of William and Margaret.'],
            'thomas' => ['Thomas Mercer', '1972-07-08', 'Family holiday organiser and enthusiastic photographer.'],
            'sarah' => ['Sarah Mercer', '1976-02-17', 'Youngest of William and Margaret’s children.'],
            'david' => ['David Mercer', '1992-09-11', 'Elaine’s son and a collector of family stories.'],
            'maya' => ['Maya Mercer', '1998-04-06', 'Thomas’s daughter and contributor to the digital archive.'],
            'james' => ['James Mercer', '2002-12-19', 'Sarah’s son and the youngest Mercer in the archive.'],
        ];
        $ids = [];
        foreach ($definitions as $key => [$name, $birthDate, $biography]) {
            $ids[$key] = (string) Str::ulid();
            $this->insert('people', [
                'id' => $ids[$key], 'family_space_id' => $familyId, 'preferred_name' => $name,
                'alternate_names' => null, 'identity_status' => 'confirmed', 'birth_date' => $birthDate,
                'birth_date_precision' => DatePrecision::Exact->value, 'is_deceased' => false,
                'death_date' => null, 'death_date_precision' => DatePrecision::Unknown->value,
                'biography' => $this->documentJson($biography), 'biography_plain_text' => $biography,
                'created_by' => $owner->id, 'confirmed_by' => $owner->id,
                'confirmed_at' => $anchor->subDays(27), 'recognition_allowed' => false,
            ], $anchor->subDays(27));
        }

        return $ids;
    }

    /** @param array<string, string> $people */
    private function relationships(string $familyId, array $people, User $owner, CarbonImmutable $anchor): void
    {
        $relations = [
            ['william', 'margaret', 'partner_of'], ['william', 'elaine', 'parent_of'],
            ['margaret', 'elaine', 'parent_of'], ['william', 'thomas', 'parent_of'],
            ['margaret', 'thomas', 'parent_of'], ['william', 'sarah', 'parent_of'],
            ['margaret', 'sarah', 'parent_of'], ['elaine', 'thomas', 'sibling_of'],
            ['thomas', 'sarah', 'sibling_of'], ['elaine', 'david', 'parent_of'],
            ['thomas', 'maya', 'parent_of'], ['sarah', 'james', 'parent_of'],
        ];
        foreach ($relations as $offset => [$subject, $related, $type]) {
            $this->insert('person_relationships', [
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId,
                'subject_person_id' => $people[$subject], 'related_person_id' => $people[$related],
                'type' => $type, 'status' => 'confirmed', 'context' => 'Synthetic Mercer family ground truth.',
                'created_by' => $owner->id, 'updated_by' => $owner->id,
            ], $anchor->subDays(26)->addMinutes($offset));
        }
    }

    /** @param array{owner: User, admin: User, member: User} $users
     * @return array<string, string>
     */
    private function events(string $familyId, array $users, CarbonImmutable $anchor): array
    {
        $definitions = [
            'wedding' => ["William and Margaret's Wedding", '1967-06-17', '1967-06-17', "St Anne's Hall, Northbridge"],
            'christmas' => ['Christmas 1984', '1984-12-25', '1984-12-26', '14 Willow Lane, Northbridge'],
            'seaside' => ['Seaside Holiday', '1999-08-14', '1999-08-21', 'Brightwater Seafront'],
            'birthday' => ["William's 80th Birthday", '2025-05-17', '2025-05-17', 'Riverside Pavilion, Northbridge'],
        ];
        $ids = [];
        foreach (array_values($definitions) as $offset => $definition) {
            [$key, $values] = [array_keys($definitions)[$offset], $definition];
            $ids[$key] = (string) Str::ulid();
            $this->insert('events', [
                'id' => $ids[$key], 'family_space_id' => $familyId, 'created_by' => $users[$offset % 3 === 0 ? 'owner' : ($offset % 3 === 1 ? 'admin' : 'member')]->id,
                'name' => $values[0],
                'description' => $this->documentJson('A landmark in the fictional Mercer family history.'),
                'description_plain_text' => 'A landmark in the fictional Mercer family history.',
                'starts_on' => $values[1], 'ends_on' => $values[2], 'location' => $values[3], 'status' => 'completed',
            ], $anchor->subDays(24 - $offset));
        }

        return $ids;
    }

    /** @param array<string, string> $events
     * @param  array{owner: User, admin: User, member: User}  $users
     * @return array<string, string>
     */
    private function albums(string $familyId, array $events, array $users, CarbonImmutable $anchor): array
    {
        $definitions = [
            'wedding' => ["William & Margaret's Wedding", 'wedding'],
            'christmas' => ['Christmas 1984', 'christmas'],
            'seaside' => ['Seaside Holiday 1999', 'seaside'],
            'birthday' => ["William's 80th Birthday", 'birthday'],
            'william' => ['William Through the Years', null],
            'holidays' => ['Mercer Family Holidays', null],
        ];
        $ids = [];
        foreach (array_values($definitions) as $offset => [$name, $eventKey]) {
            $key = array_keys($definitions)[$offset];
            $ids[$key] = (string) Str::ulid();
            $this->insert('albums', [
                'id' => $ids[$key], 'family_space_id' => $familyId,
                'created_by' => $users[['owner', 'admin', 'member'][$offset % 3]]->id,
                'name' => $name,
                'description' => $this->documentJson('A curated synthetic collection from the Mercer archive.'),
                'description_plain_text' => 'A curated synthetic collection from the Mercer archive.',
                'visibility' => 'family_space', 'event_id' => $eventKey === null ? null : $events[$eventKey],
                'guest_participation' => 'none',
            ], $anchor->subDays(22 - $offset));
        }

        return $ids;
    }

    /** @return array<string, string> */
    private function tags(string $familyId, User $creator, CarbonImmutable $anchor): array
    {
        $ids = [];
        foreach (['Wedding', 'Christmas', 'Birthday', 'Holiday', 'Seaside', 'Family', 'Car', 'Home', 'School', 'Celebration'] as $offset => $label) {
            $key = strtolower($label);
            $ids[$key] = (string) Str::ulid();
            $this->insert('tags', [
                'id' => $ids[$key], 'family_space_id' => $familyId, 'label' => $label,
                'normalized_label' => $key, 'created_by' => $creator->id,
            ], $anchor->subDays(21)->addMinutes($offset));
        }

        return $ids;
    }

    /** @param array<string, string> $events
     * @param  array<string, string>  $tags
     */
    private function eventTags(
        string $familyId,
        array $events,
        array $tags,
        User $actor,
        CarbonImmutable $anchor,
    ): void {
        $definitions = [
            'wedding' => ['wedding', 'family', 'celebration'],
            'christmas' => ['christmas', 'family', 'celebration'],
            'seaside' => ['seaside', 'holiday', 'family'],
            'birthday' => ['birthday', 'family', 'celebration'],
        ];
        foreach ($definitions as $eventKey => $tagKeys) {
            foreach ($tagKeys as $offset => $tagKey) {
                DB::table('event_tag')->insert([
                    'family_space_id' => $familyId,
                    'event_id' => $events[$eventKey],
                    'tag_id' => $tags[$tagKey],
                    'added_by' => $actor->id,
                    'created_at' => $anchor->subDays(21)->addMinutes($offset),
                ]);
            }
        }
    }

    /** @param array<string, string> $people
     * @param  array<string, string>  $events
     * @param  array<string, string>  $albums
     * @param  array<string, string>  $tags
     * @param  array{owner: User, admin: User, member: User}  $users
     * @return array<int, string>
     */
    private function photos(string $familyId, array $people, array $events, array $albums, array $tags, array $users, CarbonImmutable $anchor): array
    {
        $captions = [
            'Outside the hall', 'The wedding party', 'A quiet moment after the ceremony', 'Confetti on the steps', 'The first dance', 'Leaving for home',
            'Christmas morning', 'Margaret’s Christmas table', 'Paper hats after lunch', 'The new toy car', 'Boxing Day walk', 'Three generations by the tree',
            'First morning at Brightwater', 'Picnic before the rain', 'Sandcastle competition', 'The promenade', 'Ice creams by the pier', 'Last evening at the coast',
            'William and his first car', 'At Willow Lane', 'Elaine’s school photograph', 'Thomas behind the camera', 'Sarah in the garden', 'David’s first family holiday',
            'William arrives at eighty', 'The birthday cake', 'A room full of Mercers', 'William and Margaret together', 'Grandchildren at the pavilion', 'Stories after supper',
            'Sunday in the garden', 'Sorting Margaret’s albums', 'A winter family portrait', 'Maya brings the old camera', 'James finds the car keys', 'An undated photograph from the box',
        ];
        $personSets = [
            ['william', 'margaret'], ['william', 'margaret', 'elaine'], ['william'], ['william', 'margaret'], ['william', 'margaret'], ['margaret'],
            ['william', 'margaret', 'elaine', 'thomas', 'sarah'], ['margaret'], ['elaine', 'thomas', 'sarah'], ['thomas'], ['william'], ['william', 'margaret', 'elaine', 'thomas', 'sarah'],
            ['william', 'margaret', 'elaine', 'thomas', 'sarah', 'david', 'maya'], ['william', 'margaret', 'david'], ['david', 'maya'], ['maya'], ['sarah'], ['william'],
            ['william'], ['william'], ['elaine'], ['thomas'], ['sarah'], ['elaine', 'david'],
            ['william'], ['william', 'margaret', 'sarah'], ['william', 'margaret', 'elaine', 'thomas', 'sarah', 'david', 'maya', 'james'], ['william'], ['david', 'maya', 'james'], ['william'],
            ['margaret'], ['margaret'], ['william', 'margaret', 'elaine', 'thomas', 'sarah'], ['maya'], ['william'], ['william'],
        ];
        $dates = [
            ['1967-06-17', 'exact'], ['1967-06-17', 'exact'], ['1967-06-17', 'exact'], ['1967-06-17', 'exact'], ['1967-06-17', 'exact'], ['1967-06-01', 'month'],
            ['1984-12-25', 'exact'], ['1984-12-25', 'exact'], ['1984-12-25', 'exact'], ['1984-01-01', 'year'], ['1984-12-26', 'exact'], ['1984-12-25', 'exact'],
            ['1999-08-14', 'exact'], ['1999-08-15', 'exact'], ['1999-08-16', 'exact'], ['1999-08-17', 'exact'], ['1999-08-18', 'exact'], ['1999-08-20', 'exact'],
            ['1964-01-01', 'approximate'], ['1970-01-01', 'decade'], ['1977-01-01', 'year'], ['1992-01-01', 'year'], ['1986-06-01', 'month'], ['2000-01-01', 'approximate'],
            ['2025-05-17', 'exact'], ['2025-05-17', 'exact'], ['2025-05-17', 'exact'], ['2025-05-17', 'exact'], ['2025-05-17', 'exact'], ['2025-05-17', 'exact'],
            ['2016-06-01', 'month'], ['2023-01-01', 'year'], ['2019-12-01', 'month'], ['2024-01-01', 'year'], ['2022-01-01', 'approximate'], [null, 'unknown'],
        ];
        $photos = [];
        $albumPositions = array_fill_keys(array_keys($albums), 0);
        foreach ($captions as $index => $caption) {
            $number = $index + 1;
            $creatorKey = ['owner', 'admin', 'member'][$index % 3];
            $createdAt = $anchor->subDays(20 - ($index % 20))->addMinutes($index);
            $eventKey = $index < 6 ? 'wedding' : ($index < 12 ? 'christmas' : ($index < 18 ? 'seaside' : ($index >= 24 && $index < 30 ? 'birthday' : null)));
            $baseAlbum = $eventKey ?? ($index % 2 === 0 ? 'william' : 'holidays');
            $albumKeys = in_array($number, [21, 36], true) ? [] : [$baseAlbum];
            if (in_array($number, [1, 2, 7, 12, 13, 14, 18, 25, 28, 30], true)) {
                $albumKeys[] = in_array($baseAlbum, ['wedding', 'birthday'], true) ? 'william' : 'holidays';
            }
            $photoId = (string) Str::ulid();
            $uploadId = (string) Str::ulid();
            $photos[$number] = $photoId;
            $this->writeMedia($familyId, $uploadId, $number, count($personSets[$index]), $users[$creatorKey], $createdAt);
            $this->insert('photos', [
                'id' => $photoId, 'family_space_id' => $familyId, 'media_upload_id' => $uploadId,
                'created_by' => $users[$creatorKey]->id, 'visibility' => in_array($number, [21, 36], true) ? 'private' : 'family_space',
                'caption' => $caption, 'description' => 'A synthetic archive illustration created for the Mercer Family Demo.',
                'archive_source_description' => 'Generated demo archive card; no real photograph or biometric data.',
                'historical_date_precision' => $dates[$index][1], 'historical_date' => $dates[$index][0],
                'location_description' => $this->location($index), 'primary_event_id' => $eventKey === null ? null : $events[$eventKey],
            ], $createdAt);
            foreach ($personSets[$index] as $personKey) {
                $this->insert('photo_people', [
                    'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'photo_id' => $photoId,
                    'person_id' => $people[$personKey], 'proposal_source' => 'human', 'status' => 'approved',
                    'proposed_by' => $users[$creatorKey]->id, 'resolved_by' => $users['owner']->id, 'resolved_at' => $createdAt,
                ], $createdAt);
            }
            foreach (array_unique($albumKeys) as $albumKey) {
                $this->insert('album_photos', [
                    'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'album_id' => $albums[$albumKey],
                    'photo_id' => $photoId, 'position' => ++$albumPositions[$albumKey], 'added_by' => $users[$creatorKey]->id,
                ], $createdAt->addDay());
            }
            $tagKeys = $this->photoTags($index, $eventKey);
            foreach ($tagKeys as $tagKey) {
                DB::table('photo_tag')->insert([
                    'family_space_id' => $familyId, 'photo_id' => $photoId, 'tag_id' => $tags[$tagKey],
                    'added_by' => $users[$creatorKey]->id, 'created_at' => $createdAt->addHours(2),
                ]);
            }
        }

        return $photos;
    }

    private function writeMedia(string $familyId, string $uploadId, int $scene, int $people, User $creator, CarbonImmutable $createdAt): void
    {
        $main = $this->images->make($scene, 768, 512, $people);
        $thumb = $this->images->make($scene, 320, 320, $people);
        $mainHash = hash('sha256', $main);
        $originalKey = FamilyStorageKey::for($familyId, "media/{$uploadId}/original.png");
        $canonicalKey = FamilyStorageKey::for($familyId, "media/{$uploadId}/canonical.png");
        $this->put($originalKey, $main, $mainHash);
        $this->put($canonicalKey, $main, $mainHash);
        $this->insert('media_uploads', [
            'id' => $uploadId, 'family_space_id' => $familyId, 'user_id' => $creator->id,
            'state' => MediaUploadState::Ready->value,
            'staging_object_key' => FamilyStorageKey::for($familyId, "media-staging/{$uploadId}/upload"),
            'staging_deleted_at' => $createdAt, 'original_object_key' => $originalKey,
            'original_sha256' => $mainHash, 'byte_size' => strlen($main),
            'client_filename' => sprintf('mercer-demo-%02d.png', $scene), 'client_mime_type' => 'image/png',
            'detected_mime_type' => 'image/png', 'pixel_width' => 768, 'pixel_height' => 512,
            'original_orientation' => 1, 'canonical_object_key' => $canonicalKey,
            'canonical_mime_type' => 'image/png', 'canonical_sha256' => $mainHash,
            'upload_method' => 'single', 'idempotency_key' => sprintf('mercer-demo-%02d', $scene),
            'request_fingerprint' => hash('sha256', 'mercer-demo-'.$scene),
            'correlation_id' => (string) Str::uuid(), 'traceparent' => sprintf('00-%032x-%016x-01', $scene, $scene),
            'uploaded_at' => $createdAt,
        ], $createdAt);
        foreach ([
            'thumbnail' => [$thumb, 320, 320], 'card' => [$main, 768, 512], 'display' => [$main, 768, 512],
        ] as $transform => [$bytes, $width, $height]) {
            $hash = hash('sha256', $bytes);
            $key = FamilyStorageKey::for($familyId, "media/{$uploadId}/variants/{$transform}.v1.png");
            $this->put($key, $bytes, $hash);
            $this->insert('media_variants', [
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'media_upload_id' => $uploadId,
                'transform_name' => $transform, 'processing_version' => 1, 'object_key' => $key,
                'mime_type' => 'image/png', 'sha256' => $hash, 'pixel_width' => $width,
                'pixel_height' => $height, 'byte_size' => strlen($bytes),
            ], $createdAt);
        }
    }

    private function put(string $key, string $bytes, string $sha256): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fambam-demo-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary demo image file.');
        }
        try {
            file_put_contents($path, $bytes);
            $this->storage->finalizeWriteOnce($path, $key, $sha256);
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, string> $people
     * @param  array<string, string>  $events
     * @param  array<int, string>  $photos
     * @param  array<string, string>  $albums
     * @param  array{owner: User, admin: User, member: User}  $users
     */
    private function conversations(string $familyId, array $people, array $events, array $photos, array $albums, array $users, CarbonImmutable $anchor): void
    {
        $stories = [
            'William said the rain held off just long enough.', 'Margaret kept this print at the front of the album.',
            'Everyone remembers the paper hats more than lunch.', 'William insisted this car would last forever.',
            'Christmas at Margaret’s house, 1984.', 'The weather turned halfway through the seaside picnic.',
            'Maya wanted one more trip along the promenade.', 'Elaine found this photograph behind another print.',
            'Thomas remembers borrowing the camera for this one.', 'The cake arrived with exactly eighty candles.',
            'James asked William for the story about the old car.', 'Margaret labelled every person on the back.',
            'The family stayed talking long after supper.', 'Nobody is quite sure when this was taken.',
        ];
        $storyPhotos = [1, 2, 7, 10, 12, 14, 16, 19, 22, 25, 26, 28, 30, 36];
        foreach ($stories as $index => $body) {
            $photoCreatedAt = CarbonImmutable::parse((string) DB::table('photos')
                ->where('id', $photos[$storyPhotos[$index]])->value('created_at'));
            $storyCreatedAt = $photoCreatedAt->addDays(2)->min($anchor->subDay()->addMinutes($index));
            $subject = match ($index) {
                3 => ['person_id' => $people['william']],
                6 => ['album_id' => $albums['christmas']],
                12 => ['event_id' => $events['birthday']],
                default => ['photo_id' => $photos[$storyPhotos[$index]]],
            };
            $document = $index === 0 ? [
                'schema_version' => 1,
                'blocks' => [['type' => 'paragraph', 'content' => [
                    ['type' => 'mention', 'person_id' => $people['william'], 'label' => 'William Mercer'],
                    ['type' => 'text', 'text' => ' said the rain held off just long enough.'],
                ]]],
            ] : $this->documents->fromPlainText($body);
            $story = $this->storyWriter->create($familyId, $users[['owner', 'admin', 'member'][$index % 3]],
                $subject, $document, fn (string $personId): bool => $personId === $people['william']);
            DB::table('stories')->where('id', $story->id)->update([
                'created_at' => $storyCreatedAt, 'updated_at' => $storyCreatedAt,
            ]);
            if ($index === 0) {
                $comment = $this->storyWriter->comment($story, $users['admin'],
                    $this->documents->fromPlainText('I remember that day too.'), fn (): bool => false);
                DB::table('story_comments')->where('id', $comment->id)->update([
                    'created_at' => $storyCreatedAt->addHour(), 'updated_at' => $storyCreatedAt->addHour(),
                ]);
            }
        }

        $comments = [
            [1, 'wedding', 'admin', 'I love how happy they look here.'],
            [1, 'william', 'member', 'This belongs in William’s timeline too.'],
            [7, 'christmas', 'owner', 'The decorations stayed up until January.'],
            [12, 'christmas', 'member', 'That tree was enormous to us.'],
            [13, 'seaside', 'admin', 'Brightwater still looks exactly like this.'],
            [14, 'holidays', 'owner', 'The picnic basket survived the rain.'],
            [25, 'birthday', 'member', 'Grandad looked genuinely surprised.'],
            [28, 'william', 'admin', 'One for the William collection.'],
            [30, 'birthday', 'owner', 'A perfect end to the evening.'],
            [18, 'seaside', 'member', 'We were already planning the next holiday.'],
        ];
        foreach ($comments as $index => [$photo, $album, $author, $body]) {
            $this->insert('photo_comments', [
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'photo_id' => $photos[$photo],
                'album_id' => $albums[$album], 'author_id' => $users[$author]->id,
                'body' => $this->documentJson($body), 'body_plain_text' => $body,
            ], $anchor->subDays(8 - ($index % 8))->addMinutes($index));
        }

        $reactionTargets = [
            [1, 'wedding'], [1, 'william'], [2, 'wedding'], [7, 'christmas'], [12, 'christmas'], [13, 'seaside'],
            [14, 'seaside'], [14, 'holidays'], [18, 'seaside'], [19, 'william'], [25, 'birthday'], [25, 'william'],
            [26, 'birthday'], [27, 'birthday'], [28, 'birthday'], [28, 'william'], [29, 'birthday'], [30, 'birthday'],
        ];
        foreach ($reactionTargets as $index => [$photo, $album]) {
            $this->insert('photo_reactions', [
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'photo_id' => $photos[$photo],
                'album_id' => $albums[$album], 'user_id' => $users[['owner', 'admin', 'member'][$index % 3]]->id,
                'reaction' => ['love', 'smile', 'laugh', 'remember'][$index % 4],
            ], $anchor->subDays(6 - ($index % 6))->addMinutes($index));
        }
    }

    /** @param array<string, string> $people
     * @param  array<string, string>  $events
     * @param  array<string, string>  $albums
     * @param  array{owner: User, admin: User, member: User}  $users
     */
    private function savedSearches(string $familyId, array $people, array $events, array $albums, array $users, CarbonImmutable $anchor): void
    {
        $definitions = [
            ['owner', 'William Through the Years', ['schema_version' => 1], [$people['william']]],
            ['admin', 'Christmas Memories', ['schema_version' => 1, 'event_id' => $events['christmas']], []],
            ['member', 'Seaside Holiday', ['schema_version' => 1, 'album_id' => $albums['seaside']], []],
        ];
        foreach ($definitions as $index => [$creator, $name, $filters, $personIds]) {
            $savedSearchId = (string) Str::ulid();
            $this->insert('saved_searches', [
                'id' => $savedSearchId, 'family_space_id' => $familyId, 'created_by' => $users[$creator]->id,
                'name' => $name, 'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
            ], $anchor->subDays(4 - $index));
            foreach ($personIds as $personId) {
                DB::table('saved_search_people')->insert([
                    'saved_search_id' => $savedSearchId, 'family_space_id' => $familyId, 'person_id' => $personId,
                ]);
            }
        }
    }

    private function location(int $index): ?string
    {
        return match (true) {
            $index < 6 => "St Anne's Hall, Northbridge",
            $index < 12 => '14 Willow Lane, Northbridge',
            $index < 18 => 'Brightwater Seafront',
            $index === 20 => "St Anne's School, Northbridge",
            $index >= 24 && $index < 30 => 'Riverside Pavilion, Northbridge',
            $index % 3 === 0 => '14 Willow Lane, Northbridge',
            default => null,
        };
    }

    /** @return list<string> */
    private function photoTags(int $index, ?string $eventKey): array
    {
        $tags = ['family'];
        if ($eventKey !== null) {
            $tags[] = match ($eventKey) {
                'wedding' => 'wedding', 'christmas' => 'christmas', 'seaside' => 'seaside', default => 'birthday',
            };
        }
        if ($eventKey === 'seaside') {
            $tags[] = 'holiday';
        }
        if ($index === 18 || $index === 34) {
            $tags[] = 'car';
        }
        if ($index === 20) {
            $tags[] = 'school';
        }
        if ($index === 19 || $index === 30) {
            $tags[] = 'home';
        }
        if ($eventKey === 'wedding' || $eventKey === 'birthday') {
            $tags[] = 'celebration';
        }

        return array_values(array_unique($tags));
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, array $values, CarbonImmutable $timestamp): void
    {
        DB::table($table)->insert($values + ['created_at' => $timestamp, 'updated_at' => $timestamp]);
    }

    private function documentJson(string $text): string
    {
        return json_encode($this->documents->fromPlainText($text), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int|string> */
    private function summary(FamilySpace $family, User $owner): array
    {
        return DB::transaction(function () use ($family, $owner): array {
            $this->databaseTenantContext->establishUser($owner);
            $this->databaseTenantContext->establishFamilySpace($family);
            $id = $family->id;

            return [
                'family_space_id' => $id, 'slug' => $family->slug,
                'memberships' => DB::table('family_space_memberships')->where('family_space_id', $id)->where('state', 'active')->count(),
                'people' => DB::table('people')->where('family_space_id', $id)->count(),
                'relationships' => DB::table('person_relationships')->where('family_space_id', $id)->count(),
                'events' => DB::table('events')->where('family_space_id', $id)->count(),
                'albums' => DB::table('albums')->where('family_space_id', $id)->count(),
                'media_uploads' => DB::table('media_uploads')->where('family_space_id', $id)->count(),
                'photos' => DB::table('photos')->where('family_space_id', $id)->count(),
                'stories' => DB::table('stories')->where('family_space_id', $id)->count(),
                'story_comments' => DB::table('story_comments')->where('family_space_id', $id)->count(),
                'comments' => DB::table('photo_comments')->where('family_space_id', $id)->count(),
                'reactions' => DB::table('photo_reactions')->where('family_space_id', $id)->count(),
                'tags' => DB::table('tags')->where('family_space_id', $id)->count(),
                'saved_searches' => DB::table('saved_searches')->where('family_space_id', $id)->count(),
            ];
        });
    }

    /** @param array<string, int|string> $summary */
    private function isComplete(array $summary): bool
    {
        return $summary['memberships'] === 3 && $summary['people'] === 8
            && $summary['relationships'] === 12 && $summary['events'] === 4
            && $summary['albums'] === 6 && $summary['media_uploads'] === 36
            && $summary['photos'] === 36 && $summary['stories'] === 14 && $summary['story_comments'] === 1
            && $summary['comments'] === 10 && $summary['reactions'] === 18
            && $summary['tags'] === 10 && $summary['saved_searches'] === 3;
    }
}
