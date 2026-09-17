<?php

namespace App\Services;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\PhotoReaction;
use App\Models\Reaction;
use App\Models\Story;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class LoveManager
{
    public function __construct(private readonly LoveNotificationManager $notifications) {}

    public function save(Album|FamilyEvent|Story $target, User $actor, Request $request): void
    {
        DB::transaction(function () use ($target, $actor, $request): void {
            $target::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $reaction = Reaction::query()->firstOrCreate([
                'family_space_id' => $target->family_space_id,
                $this->column($target) => $target->getKey(),
                'user_id' => $actor->id,
            ], ['reaction' => 'love']);
            if ($reaction->wasRecentlyCreated) {
                $this->notifications->added($target, $actor, $request);
            }
        });
    }

    public function remove(Album|FamilyEvent|Story $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor): void {
            $target::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $removed = Reaction::query()->where($this->column($target), $target->getKey())
                ->where('user_id', $actor->id)->delete();
            if ($removed > 0) {
                $this->notifications->removed($target, $actor);
            }
        });
    }

    /** @return array{count:int,loved_by_me:bool,reactors:list<array<string, mixed>>} */
    public function summary(Photo|Album|FamilyEvent|Story $target, User $viewer, ?Album $album = null): array
    {
        $ids = $target instanceof Photo
            ? PhotoReaction::query()->where('photo_id', $target->id)->where('album_id', $album?->id)
                ->where('reaction', 'love')->pluck('user_id')->all()
            : Reaction::query()->where($this->column($target), $target->getKey())
                ->where('reaction', 'love')->pluck('user_id')->all();
        $users = User::query()->whereIn('id', $ids)->orderBy('id')->get();
        $linked = PersonAccountLink::query()->whereIn('user_id', $ids)
            ->with('person')->get()->groupBy('user_id');
        $reactors = [];
        foreach ($users as $user) {
            $person = $linked->get($user->id)?->pluck('person')
                ->first(fn ($person): bool => $person !== null && Gate::forUser($viewer)->allows('view', $person));
            $reactors[] = ['user_id' => $user->id, 'name' => $user->name,
                'person' => $person === null ? null : ['id' => $person->id, 'name' => $person->preferred_name]];
        }

        return ['count' => count($reactors), 'loved_by_me' => in_array($viewer->id, $ids, true),
            'reactors' => $reactors];
    }

    private function column(Album|FamilyEvent|Story $target): string
    {
        return match (true) {
            $target instanceof Album => 'album_id',
            $target instanceof FamilyEvent => 'event_id',
            $target instanceof Story => 'story_id',
        };
    }
}
