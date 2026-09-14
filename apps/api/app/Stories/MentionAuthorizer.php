<?php

namespace App\Stories;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Queries\PhotoQuery;
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
}
