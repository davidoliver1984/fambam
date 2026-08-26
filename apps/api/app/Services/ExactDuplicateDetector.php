<?php

namespace App\Services;

use App\Models\DuplicateCandidate;
use App\Models\DuplicateDecision;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Models\User;
use App\Queries\PhotoQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ExactDuplicateDetector
{
    public function __construct(private readonly PhotoQuery $photos) {}

    public function lock(MediaUpload $upload): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                $upload->family_space_id.':'.$upload->original_sha256,
            ]);
        }
    }

    /** @return Collection<int, Photo> */
    public function visibleMatches(
        MediaUpload $upload,
        User $viewer,
        FamilySpaceMembership $membership,
    ): Collection {
        if ($upload->original_sha256 === null) {
            return new Collection;
        }

        return $this->photos->visibleToMembership($viewer, $membership, $upload->family_space_id)
            ->whereHas('mediaUpload', fn ($query) => $query
                ->where('state', 'ready')
                ->where('original_sha256', $upload->original_sha256)
                ->whereKeyNot($upload->id))
            ->orderBy('photos.id')
            ->get();
    }

    public function recordSeparateDecision(Photo $photo, Photo $match, User $actor): DuplicateDecision
    {
        [$low, $high] = $this->orderedPair($photo->id, $match->id);
        $decision = DuplicateDecision::query()->lockForUpdate()
            ->where('family_space_id', $photo->family_space_id)
            ->where('photo_low_id', $low)
            ->where('photo_high_id', $high)
            ->first();

        if ($decision === null) {
            return DuplicateDecision::query()->create([
                'family_space_id' => $photo->family_space_id,
                'photo_low_id' => $low,
                'photo_high_id' => $high,
                'source' => 'exact_creation_choice',
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
        }

        if ($decision->reopened_at !== null) {
            $decision->update([
                'source' => 'exact_creation_choice',
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
            ]);
        }

        return $decision;
    }

    public function generateCandidatesFor(Photo $photo): void
    {
        $photo->loadMissing('mediaUpload');
        $checksum = $photo->mediaUpload->original_sha256;
        if ($checksum === null) {
            return;
        }

        $matches = Photo::query()
            ->where('family_space_id', $photo->family_space_id)
            ->whereKeyNot($photo->id)
            ->whereHas('mediaUpload', fn ($query) => $query
                ->where('state', 'ready')
                ->where('original_sha256', $checksum))
            ->get();

        foreach ($matches as $match) {
            [$low, $high] = $this->orderedPair($photo->id, $match->id);
            $settled = DuplicateDecision::query()
                ->where('family_space_id', $photo->family_space_id)
                ->where('photo_low_id', $low)
                ->where('photo_high_id', $high)
                ->whereNull('reopened_at')
                ->exists();
            if ($settled) {
                continue;
            }

            DuplicateCandidate::query()->updateOrCreate([
                'family_space_id' => $photo->family_space_id,
                'photo_id' => $low,
                'candidate_photo_id' => $high,
                'source' => 'exact',
            ], [
                'status' => 'pending',
                'matched_sha256' => $checksum,
            ]);
        }
    }

    /** @return array{string, string} */
    private function orderedPair(string $first, string $second): array
    {
        return strcmp($first, $second) < 0 ? [$first, $second] : [$second, $first];
    }
}
