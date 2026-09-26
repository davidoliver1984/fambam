<?php

namespace App\Stories;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Queries\PhotoQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MentionAuthorizer
{
    public function __construct(private readonly PhotoQuery $photos) {}

    /** @return callable(string): bool */
    public function for(User $actor, Model $subject): callable
    {
        return function (string $personId) use ($actor, $subject): bool {
            $person = Person::query()->find($personId);

            return $person !== null && $this->allows($actor, $subject, $person);
        };
    }

    public function allows(User $actor, Model $subject, Person $person): bool
    {
        if (Gate::forUser($actor)->allows('view', $person)) {
            return true;
        }

        $contextPhotoIds = match (true) {
            $subject instanceof Photo => [$subject->id],
            $subject instanceof Album => DB::table('album_photos')->where('album_id', $subject->id)->pluck('photo_id')->all(),
            $subject instanceof FamilyEvent => DB::table('photos')->where('primary_event_id', $subject->id)->pluck('id')
                ->merge(DB::table('album_photos')->join('albums', 'albums.id', '=', 'album_photos.album_id')
                    ->where('albums.event_id', $subject->id)->pluck('album_photos.photo_id'))->unique()->all(),
            default => [],
        };
        if ($contextPhotoIds === []) {
            return false;
        }

        $visiblePhotoIds = $this->photos->visibleTo($actor)->whereIn('photos.id', $contextPhotoIds)->pluck('photos.id');

        return DB::table('photo_people')->where('person_id', $person->id)->where('status', 'approved')
            ->whereIn('photo_id', $visiblePhotoIds)->exists();
    }

    /** @return list<array{id: string, label: string}> */
    public function suggestions(User $actor, Model $subject, string $prefix): array
    {
        $familySpaceId = $subject->getAttribute('family_space_id');
        if (! is_string($familySpaceId)) {
            throw new \LogicException('Mention subjects must belong to a Family Space.');
        }
        $people = Person::query()
            ->where('family_space_id', $familySpaceId)
            ->whereRaw('LOWER(preferred_name) LIKE ?', [mb_strtolower(trim($prefix)).'%']);

        if (Gate::forUser($actor)->denies('viewAny', Person::class)) {
            $contextPhotoIds = $this->contextPhotoIds($subject);
            if ($contextPhotoIds === []) {
                return [];
            }
            $visiblePhotoIds = $this->photos->visibleTo($actor)->setEagerLoads([])
                ->whereIn('photos.id', $contextPhotoIds)->select('photos.id');
            $people->whereHas('photoPeople', fn (Builder $links) => $links
                ->where('status', 'approved')->whereIn('photo_id', $visiblePhotoIds));
        }

        return $people->orderBy('preferred_name')->orderBy('id')->limit(8)
            ->get(['id', 'preferred_name'])
            ->map(fn (Person $person): array => ['id' => $person->id, 'label' => $person->preferred_name])
            ->all();
    }

    /** @return list<string> */
    private function contextPhotoIds(Model $subject): array
    {
        return match (true) {
            $subject instanceof Photo => [$subject->id],
            $subject instanceof Album => DB::table('album_photos')
                ->where('family_space_id', $subject->family_space_id)
                ->where('album_id', $subject->id)->pluck('photo_id')->all(),
            $subject instanceof FamilyEvent => DB::table('photos')
                ->where('family_space_id', $subject->family_space_id)
                ->where('primary_event_id', $subject->id)->pluck('id')
                ->merge(DB::table('album_photos')->join('albums', function ($join): void {
                    $join->on('albums.id', '=', 'album_photos.album_id')
                        ->on('albums.family_space_id', '=', 'album_photos.family_space_id');
                })->where('albums.family_space_id', $subject->family_space_id)
                    ->where('albums.event_id', $subject->id)->pluck('album_photos.photo_id'))
                ->unique()->values()->all(),
            default => [],
        };
    }
}
