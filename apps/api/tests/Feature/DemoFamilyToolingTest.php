<?php

namespace Tests\Feature;

use App\Demo\DemoArchiveImage;
use App\Demo\DemoFamilyBuilder;
use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Media\FamilyMediaStorageCleaner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DemoFamilyToolingTest extends TestCase
{
    use RefreshDatabase;

    private DemoObjectStorage $storage;

    private DemoStorageCleaner $cleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new DemoObjectStorage;
        $this->cleaner = new DemoStorageCleaner($this->storage);
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(FamilyMediaStorageCleaner::class, $this->cleaner);
    }

    public function test_seed_refuses_outside_local_even_when_enabled(): void
    {
        config()->set('demo-family.enabled', true);

        $this->artisan('fambam:demo-family:seed')
            ->expectsOutputToContain('available only when APP_ENV=local')
            ->assertFailed();
    }

    public function test_seed_refuses_without_explicit_opt_in(): void
    {
        $this->useLocalEnvironment();
        config()->set('demo-family.enabled', false);

        $this->artisan('fambam:demo-family:seed')
            ->expectsOutputToContain('requires FAMBAM_DEMO_SEEDING_ENABLED=true')
            ->assertFailed();
    }

    public function test_reset_requires_force(): void
    {
        $this->useLocalEnvironment();
        config()->set('demo-family.enabled', true);

        $this->artisan('fambam:demo-family:reset')
            ->expectsOutputToContain('requires --force')
            ->assertFailed();
    }

    public function test_reset_applies_both_environment_guards(): void
    {
        config()->set('demo-family.enabled', true);
        $this->artisan('fambam:demo-family:reset', ['--force' => true])
            ->expectsOutputToContain('available only when APP_ENV=local')->assertFailed();

        $this->useLocalEnvironment();
        config()->set('demo-family.enabled', false);
        $this->artisan('fambam:demo-family:reset', ['--force' => true])
            ->expectsOutputToContain('requires FAMBAM_DEMO_SEEDING_ENABLED=true')->assertFailed();
    }

    public function test_demo_is_idempotent_interconnected_and_reset_is_isolated(): void
    {
        $this->useLocalEnvironment();
        config()->set('demo-family.enabled', true);
        [$privateFamily, $privateOwner] = $this->privateFamily();

        $first = $this->app->make(DemoFamilyBuilder::class)->seed();
        $missingObject = array_key_first($this->storage->objects);
        $this->assertNotNull($missingObject);
        unset($this->storage->objects[$missingObject]);
        $second = $this->app->make(DemoFamilyBuilder::class)->seed();

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(8, $second['people']);
        $this->assertSame(12, $second['relationships']);
        $this->assertSame(4, $second['events']);
        $this->assertSame(6, $second['albums']);
        $this->assertSame(36, $second['photos']);
        $this->assertSame(14, $second['stories']);
        $this->assertSame(1, $second['story_comments']);
        $this->assertSame(10, $second['comments']);
        $this->assertSame(18, $second['reactions']);
        $this->assertSame(3, $second['saved_searches']);
        $this->assertCount(180, $this->storage->objects);

        $demoId = (string) $first['family_space_id'];
        $this->assertSame(10, DB::table('album_photos')->where('family_space_id', $demoId)
            ->select('photo_id')->groupBy('photo_id')->havingRaw('COUNT(*) > 1')->count());
        $this->assertSame(3, DB::table('person_account_links')->where('family_space_id', $demoId)->count());
        $this->assertSame(12, DB::table('event_tag')->where('family_space_id', $demoId)->count());
        $this->assertSame(15, DB::table('photo_people')->where('family_space_id', $demoId)
            ->select('photo_id')->groupBy('photo_id')->havingRaw('COUNT(*) > 1')->count());
        $this->assertSame(1, DB::table('saved_search_people')->where('family_space_id', $demoId)->count());
        $this->assertSame(0, DB::table('saved_searches')->where('family_space_id', $demoId)
            ->whereRaw("CAST(filters AS TEXT) LIKE '%person_ids%'")->count());
        $this->assertSame(3, DB::table('saved_searches')->where('family_space_id', $demoId)
            ->distinct()->count('created_by'));
        $this->assertSame(6, DB::table('photos')->where('family_space_id', $demoId)
            ->distinct()->count('historical_date_precision'));
        $this->assertSame(2, DB::table('photos')->where('family_space_id', $demoId)
            ->where('visibility', 'private')->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('album_photos')->whereColumn('album_photos.photo_id', 'photos.id'))->count());

        $multiAlbumPhoto = DB::table('album_photos')->where('family_space_id', $demoId)
            ->select('photo_id')->groupBy('photo_id')->havingRaw('COUNT(*) > 1')->first();
        $this->assertNotNull($multiAlbumPhoto);
        $this->assertGreaterThanOrEqual(2, DB::table('photo_comments')
            ->where('family_space_id', $demoId)->where('photo_id', $multiAlbumPhoto->photo_id)
            ->distinct()->count('album_id'));

        $william = DB::table('people')->where('family_space_id', $demoId)->where('preferred_name', 'William Mercer')->first();
        $this->assertNotNull($william);
        $this->assertGreaterThanOrEqual(10, DB::table('photo_people')->where('family_space_id', $demoId)
            ->where('person_id', $william->id)->where('status', 'approved')->count());
        $this->assertSame(11, DB::table('stories')->where('family_space_id', $demoId)->whereNotNull('photo_id')->count());
        $this->assertSame(1, DB::table('stories')->where('family_space_id', $demoId)->whereNotNull('person_id')->count());
        $this->assertSame(1, DB::table('stories')->where('family_space_id', $demoId)->whereNotNull('album_id')->count());
        $this->assertSame(1, DB::table('stories')->where('family_space_id', $demoId)->whereNotNull('event_id')->count());
        $this->assertSame(1, DB::table('story_person_mentions')->where('family_space_id', $demoId)
            ->where('person_id', $william->id)->count());
        $this->assertSame(1, DB::table('story_comments')->where('family_space_id', $demoId)->count());
        $this->assertSame(8, DB::table('people')->where('family_space_id', $demoId)
            ->whereNotNull('biography_plain_text')->count());
        $this->assertSame(6, DB::table('albums')->where('family_space_id', $demoId)
            ->whereNotNull('description_plain_text')->count());
        $this->assertSame(4, DB::table('events')->where('family_space_id', $demoId)
            ->whereNotNull('description_plain_text')->count());
        $this->assertSame(10, DB::table('photo_comments')->where('family_space_id', $demoId)
            ->whereNotNull('body_plain_text')->count());
        $this->assertTrue(DB::table('events')->join('albums', 'albums.event_id', '=', 'events.id')
            ->join('album_photos', 'album_photos.album_id', '=', 'albums.id')
            ->join('stories', 'stories.photo_id', '=', 'album_photos.photo_id')
            ->join('photo_people', 'photo_people.photo_id', '=', 'album_photos.photo_id')
            ->where('events.family_space_id', $demoId)->where('photo_people.person_id', $william->id)->exists());

        $this->artisan('fambam:demo-family:reset', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('family_spaces', ['id' => $demoId, 'status' => 'deleted']);
        $this->assertDatabaseMissing('family_space_memberships', ['family_space_id' => $demoId, 'state' => 'active']);
        $this->assertDatabaseHas('family_spaces', ['id' => $privateFamily->id, 'name' => 'Private Family Archive']);
        $this->assertDatabaseHas('family_space_memberships', [
            'family_space_id' => $privateFamily->id, 'user_id' => $privateOwner->id,
        ]);
        $this->assertSame([$demoId], $this->cleaner->deletedFamilyIds);

        $restored = $this->app->make(DemoFamilyBuilder::class)->seed();
        $this->assertTrue($restored['created']);
        $this->assertSame($demoId, $restored['family_space_id']);
        $this->assertSame(36, $restored['photos']);
    }

    public function test_generated_archive_asset_is_a_valid_deterministic_png(): void
    {
        $image = $this->app->make(DemoArchiveImage::class);
        $first = $image->make(4, 80, 60, 3);

        $this->assertSame($first, $image->make(4, 80, 60, 3));
        $this->assertNotSame($first, $image->make(5, 80, 60, 3));
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($first, 0, 8));
    }

    private function useLocalEnvironment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
    }

    /** @return array{FamilySpace, User} */
    private function privateFamily(): array
    {
        $owner = User::factory()->create(['email' => 'private-owner@example.test']);
        $family = FamilySpace::query()->create([
            'name' => 'Private Family Archive', 'slug' => 'private-family-archive',
            'status' => FamilySpaceStatus::Active,
        ]);
        FamilySpaceMembership::query()->create([
            'family_space_id' => $family->id, 'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner, 'state' => MembershipState::Active,
        ]);

        return [$family, $owner];
    }
}

final class DemoObjectStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used by demo tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        throw new \LogicException('Not used by demo tests.');
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $contents = file_get_contents($localPath);
        $this->assertHash($contents, $sha256);
        if (isset($this->objects[$key]) && $this->objects[$key] !== $contents) {
            throw new \RuntimeException('Object already exists with different bytes.');
        }
        $this->objects[$key] = $contents;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    private function assertHash(string|false $contents, string $expected): void
    {
        if (! is_string($contents) || hash('sha256', $contents) !== $expected) {
            throw new \RuntimeException('Invalid demo object checksum.');
        }
    }
}

final class DemoStorageCleaner implements FamilyMediaStorageCleaner
{
    /** @var list<string> */
    public array $deletedFamilyIds = [];

    public function __construct(private readonly DemoObjectStorage $storage) {}

    public function deleteFamilyMedia(string $familySpaceId): void
    {
        $this->deletedFamilyIds[] = $familySpaceId;
        foreach (array_keys($this->storage->objects) as $key) {
            if (str_starts_with($key, "families/{$familySpaceId}/")) {
                unset($this->storage->objects[$key]);
            }
        }
    }
}
