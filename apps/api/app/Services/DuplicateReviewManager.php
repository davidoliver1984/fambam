<?php

namespace App\Services;

use App\Models\DuplicateCandidate;
use App\Models\DuplicateDecision;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DuplicateReviewManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return Collection<int, DuplicateCandidate> */
    public function pending(string $familySpaceId): Collection
    {
        return DuplicateCandidate::query()
            ->with(['photo.mediaUpload', 'candidatePhoto.mediaUpload'])
            ->where('family_space_id', $familySpaceId)
            ->where('status', 'pending')
            ->whereHas('photo', fn ($query) => $query->whereNull('photos.deleted_at'))
            ->whereHas('candidatePhoto', fn ($query) => $query->whereNull('photos.deleted_at'))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('duplicate_decisions')
                    ->whereColumn('duplicate_decisions.family_space_id', 'duplicate_candidates.family_space_id')
                    ->whereColumn('duplicate_decisions.photo_low_id', 'duplicate_candidates.photo_id')
                    ->whereColumn('duplicate_decisions.photo_high_id', 'duplicate_candidates.candidate_photo_id')
                    ->whereNull('duplicate_decisions.reopened_at');
            })
            ->orderByRaw("CASE source WHEN 'exact' THEN 0 WHEN 'perceptual' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get()
            ->unique(fn (DuplicateCandidate $candidate): string => $candidate->photo_id.':'.$candidate->candidate_photo_id)
            ->values();
    }

    /** @return Collection<int, DuplicateDecision> */
    public function settled(string $familySpaceId): Collection
    {
        return DuplicateDecision::query()
            ->with(['lowPhoto.mediaUpload', 'highPhoto.mediaUpload'])
            ->where('family_space_id', $familySpaceId)
            ->whereNull('reopened_at')
            ->whereHas('lowPhoto', fn ($query) => $query->whereNull('photos.deleted_at'))
            ->whereHas('highPhoto', fn ($query) => $query->whereNull('photos.deleted_at'))
            ->latest('decided_at')
            ->get();
    }

    public function flag(Photo $photo, Photo $candidate, User $actor, Request $request): DuplicateCandidate
    {
        if ($photo->id === $candidate->id || $photo->family_space_id !== $candidate->family_space_id) {
            throw ValidationException::withMessages([
                'candidate_photo_id' => ['Choose a different visible Photo from this Family Space.'],
            ]);
        }

        return DB::transaction(function () use ($photo, $candidate, $actor, $request): DuplicateCandidate {
            [$low, $high] = $this->orderedPair($photo->id, $candidate->id);
            if (DuplicateDecision::query()->where('family_space_id', $photo->family_space_id)
                ->where('photo_low_id', $low)->where('photo_high_id', $high)
                ->whereNull('reopened_at')->exists()) {
                throw ValidationException::withMessages([
                    'candidate_photo_id' => ['This pair has already been reviewed.'],
                ]);
            }

            $record = DuplicateCandidate::query()
                ->where('family_space_id', $photo->family_space_id)
                ->where('photo_id', $low)
                ->where('candidate_photo_id', $high)
                ->where('status', 'pending')
                ->first();
            if ($record === null) {
                $record = DuplicateCandidate::query()->create([
                    'family_space_id' => $photo->family_space_id,
                    'photo_id' => $low,
                    'candidate_photo_id' => $high,
                    'source' => 'member_flagged',
                    'status' => 'pending',
                ]);
            }

            $this->audit->record('photo_duplicate.flagged', $record, $actor, $request);

            return $record;
        });
    }

    public function dismiss(DuplicateCandidate $candidate, User $actor, Request $request): DuplicateDecision
    {
        return DB::transaction(function () use ($candidate, $actor, $request): DuplicateDecision {
            $locked = DuplicateCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            [$low, $high] = $this->orderedPair($locked->photo_id, $locked->candidate_photo_id);
            $decision = DuplicateDecision::query()->lockForUpdate()
                ->where('family_space_id', $locked->family_space_id)
                ->where('photo_low_id', $low)
                ->where('photo_high_id', $high)
                ->first();
            $attributes = [
                'source' => $this->decisionSource($locked->source),
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
            ];
            if ($decision === null) {
                $decision = DuplicateDecision::query()->create([
                    'family_space_id' => $locked->family_space_id,
                    'photo_low_id' => $low,
                    'photo_high_id' => $high,
                    ...$attributes,
                ]);
            } else {
                $decision->update($attributes);
            }

            DuplicateCandidate::query()->where('family_space_id', $locked->family_space_id)
                ->where('photo_id', $low)->where('candidate_photo_id', $high)
                ->where('status', 'pending')->update(['status' => 'dismissed']);
            $this->audit->record('photo_duplicate.candidate_dismissed', $locked, $actor, $request);
            $this->audit->record('photo_duplicate.decision_recorded', $decision, $actor, $request, [
                'resolution' => 'not_a_duplicate',
            ]);

            return $decision;
        });
    }

    public function reopen(DuplicateDecision $decision, User $actor, Request $request): DuplicateDecision
    {
        return DB::transaction(function () use ($decision, $actor, $request): DuplicateDecision {
            $locked = DuplicateDecision::query()->lockForUpdate()->findOrFail($decision->id);
            if ($locked->reopened_at === null) {
                $locked->update(['reopened_by' => $actor->id, 'reopened_at' => now()]);
                $this->audit->record('photo_duplicate.decision_reopened', $locked, $actor, $request);
            }

            return $locked;
        });
    }

    /** @return array{string, string} */
    private function orderedPair(string $first, string $second): array
    {
        return strcmp($first, $second) < 0 ? [$first, $second] : [$second, $first];
    }

    private function decisionSource(string $candidateSource): string
    {
        return match ($candidateSource) {
            'member_flagged' => 'member_flagged_review',
            'perceptual' => 'perceptual_review',
            default => 'exact_creation_choice',
        };
    }
}
