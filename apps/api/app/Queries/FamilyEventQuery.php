<?php

namespace App\Queries;

use App\Models\FamilyEvent;
use App\Models\Person;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FamilyEventQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int, FamilyEvent> */
    public function all(): Collection
    {
        return FamilyEvent::query()->with('creator:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->orderByRaw('starts_on IS NULL')
            ->orderBy('starts_on')
            ->orderBy('name')
            ->get();
    }

    public function find(string $id): FamilyEvent
    {
        return FamilyEvent::query()->with(['creator:id,name', 'albums.creator:id,name'])
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($id) ?? throw new NotFoundHttpException;
    }

    public function findDeleted(string $id): FamilyEvent
    {
        return FamilyEvent::onlyTrashed()->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($id) ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, FamilyEvent> */
    public function deleted(): Collection
    {
        return FamilyEvent::onlyTrashed()->with('creator:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->latest('deleted_at')->get();
    }

    /** @return Collection<int, Person> */
    public function attendees(FamilyEvent $event): Collection
    {
        return Person::query()->where('family_space_id', $event->family_space_id)
            ->whereHas('photoPeople', function (Builder $association) use ($event): void {
                $association->where('status', 'approved')
                    ->whereHas('photo', fn (Builder $photo) => $photo
                        ->where('primary_event_id', $event->id)
                        ->orWhereHas('albums', fn (Builder $album) => $album->where('albums.event_id', $event->id)));
            })->orderBy('preferred_name')->get();
    }

    /** @return Collection<int, FamilyEvent> */
    public function forPerson(Person $person): Collection
    {
        return FamilyEvent::query()->where('family_space_id', $person->family_space_id)
            ->where(function (Builder $event) use ($person): void {
                $event->whereHas('primaryPhotos.photoPeople', fn (Builder $association) => $association
                    ->where('person_id', $person->id)->where('status', 'approved'))
                    ->orWhereHas('albums.photos.photoPeople', fn (Builder $association) => $association
                        ->where('person_id', $person->id)->where('status', 'approved'));
            })->orderByRaw('starts_on IS NULL')->orderBy('starts_on')->get();
    }

    /** @return Collection<int, FamilyEvent> */
    public function duplicateCandidates(FamilyEvent $event): Collection
    {
        return $this->all()->reject(fn (FamilyEvent $candidate): bool => $candidate->id === $event->id)
            ->filter(function (FamilyEvent $candidate) use ($event): bool {
                if ($this->normalize($candidate->name) === $this->normalize($event->name)) {
                    return true;
                }
                if ($event->starts_on === null || $candidate->starts_on === null
                    || $event->location === null || $candidate->location === null) {
                    return false;
                }

                return abs($event->starts_on->diffInDays($candidate->starts_on, false)) <= 7
                    && $this->normalize($event->location) === $this->normalize($candidate->location);
            })->values();
    }

    private function normalize(string $value): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }
}
