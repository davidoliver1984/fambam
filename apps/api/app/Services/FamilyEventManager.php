<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FamilyEventManager
{
    private const FIELDS = ['name', 'description', 'starts_on', 'ends_on', 'location', 'status'];

    public function __construct(private readonly AuditRecorder $audit) {}

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
            $this->audit->record('event.created', $event, $actor, $request);

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
            $this->audit->record('event.updated', $locked, $actor, $request, [
                'changed_fields' => array_keys($locked->getChanges()),
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
        foreach (['name', 'description', 'location'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = trim((string) ($attributes[$field] ?? '')) ?: null;
            }
        }
        if (array_key_exists('name', $attributes) && $attributes['name'] === null) {
            throw ValidationException::withMessages(['name' => ['The Event name must not be blank.']]);
        }

        return $attributes;
    }
}
