<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\FamilyActivityType;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use App\Stories\MentionAuthorizer;
use App\Stories\RichTextFieldWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyEventManager
{
    private const FIELDS = ['name', 'description', 'starts_on', 'ends_on', 'location', 'status'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FamilyActivityRecorder $activities,
        private readonly RichTextFieldWriter $richText,
        private readonly MentionAuthorizer $mentionAuthorizer,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(FamilySpace $family, User $actor, array $input, Request $request): FamilyEvent
    {
        return DB::transaction(function () use ($family, $actor, $input, $request): FamilyEvent {
            $event = FamilyEvent::query()->create([
                'family_space_id' => $family->id,
                'created_by' => $actor->id,
                'status' => EventStatus::tryFrom((string) ($input['status'] ?? '')) ?? EventStatus::Planned,
                ...$this->attributes($input),
            ]);
            if (array_key_exists('description', $input)) {
                $description = $this->richText->synchronize($event, 'event_description_mentions', 'event_id',
                    $input['description'], $this->mentionAuthorizer->for($actor, $event));
                $event->update(['description' => $description]);
            }
            if (array_key_exists('person_ids', $input)) {
                $this->syncPeople($event, $actor, $input['person_ids']);
            }
            $this->syncTags($event, $actor, $input['tags'] ?? []);
            $this->audit->record('event.created', $event, $actor, $request);
            $this->activities->record(
                $event->family_space_id,
                $actor->id,
                FamilyActivityType::EventCreated,
                subjectEventId: $event->id,
            );

            return $event->load('creator:id,name');
        });
    }

    /** @param array<string, mixed> $input */
    public function update(FamilyEvent $event, User $actor, array $input, Request $request): FamilyEvent
    {
        return DB::transaction(function () use ($event, $actor, $input, $request): FamilyEvent {
            $locked = FamilyEvent::query()->lockForUpdate()->findOrFail($event->id);
            $attributes = $this->attributes($input);
            $starts = $attributes['starts_on'] ?? $locked->starts_on?->format('Y-m-d');
            $ends = array_key_exists('ends_on', $attributes)
                ? $attributes['ends_on'] : $locked->ends_on?->format('Y-m-d');
            if ($starts !== null && $ends !== null && $ends < $starts) {
                throw ValidationException::withMessages(['ends_on' => ['The end date must not precede the start date.']]);
            }
            $locked->update($attributes);
            $changedFields = array_keys($locked->getChanges());
            if (array_key_exists('description', $input)) {
                $description = $this->richText->synchronize($locked, 'event_description_mentions', 'event_id',
                    $input['description'], $this->mentionAuthorizer->for($actor, $locked));
                $locked->update(['description' => $description]);
                $changedFields = array_values(array_unique([...$changedFields, ...array_keys($locked->getChanges())]));
            }
            if (array_key_exists('person_ids', $input)) {
                $this->syncPeople($locked, $actor, $input['person_ids']);
                $changedFields[] = 'person_ids';
            }
            if (array_key_exists('tags', $input) && $this->syncTags($locked, $actor, $input['tags'])) {
                $changedFields[] = 'tags';
            }
            $this->audit->record('event.updated', $locked, $actor, $request, [
                'changed_fields' => array_values(array_unique($changedFields)),
            ]);

            return $locked->load('creator:id,name');
        });
    }

    public function delete(FamilyEvent $event, User $actor, Request $request): void
    {
        DB::transaction(function () use ($event, $actor, $request): void {
            $locked = FamilyEvent::query()->lockForUpdate()->findOrFail($event->id);
            $locked->delete();
            $this->audit->record('event.removed', $locked, $actor, $request);
        });
    }

    public function restore(FamilyEvent $event, User $actor, Request $request): FamilyEvent
    {
        return DB::transaction(function () use ($event, $actor, $request): FamilyEvent {
            $locked = FamilyEvent::onlyTrashed()->lockForUpdate()->findOrFail($event->id);
            $locked->restore();
            $this->audit->record('event.restored', $locked, $actor, $request);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        $attributes = Arr::only($input, self::FIELDS);
        foreach (['name', 'location'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = trim((string) ($attributes[$field] ?? '')) ?: null;
            }
        }
        if (array_key_exists('name', $attributes) && $attributes['name'] === null) {
            throw ValidationException::withMessages(['name' => ['The Event name must not be blank.']]);
        }

        return $attributes;
    }

    /** @param list<string> $personIds */
    private function syncPeople(FamilyEvent $event, User $actor, array $personIds): void
    {
        $ids = array_values(array_unique($personIds));
        if (Person::query()->where('family_space_id', $event->family_space_id)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['person_ids' => ['One or more selected People are unavailable in this Family Space.']]);
        }
        DB::table('event_people')->where('event_id', $event->id)->whereNotIn('person_id', $ids)->delete();
        foreach ($ids as $id) {
            DB::table('event_people')->insertOrIgnore(['id' => (string) Str::ulid(),
                'family_space_id' => $event->family_space_id, 'event_id' => $event->id,
                'person_id' => $id, 'added_by' => $actor->id, 'created_at' => now()]);
        }
    }

    /** @param list<string> $labels */
    private function syncTags(FamilyEvent $event, User $actor, array $labels): bool
    {
        $normalized = [];
        foreach ($labels as $label) {
            $display = preg_replace('/\s+/u', ' ', trim($label)) ?? '';
            if ($display !== '') {
                $normalized[mb_strtolower($display)] ??= $display;
            }
        }

        $tagIds = [];
        foreach ($normalized as $key => $display) {
            $tag = Tag::query()->firstOrCreate(
                ['family_space_id' => $event->family_space_id, 'normalized_label' => $key],
                ['label' => $display, 'created_by' => $actor->id],
            );
            $tagIds[$tag->id] = [
                'family_space_id' => $event->family_space_id,
                'added_by' => $actor->id,
                'created_at' => now(),
            ];
        }

        $existingIds = $event->tags()->pluck('tags.id')->sort()->values()->all();
        $nextIds = collect(array_keys($tagIds))->sort()->values()->all();
        $event->tags()->sync($tagIds);

        return $existingIds !== $nextIds;
    }
}
