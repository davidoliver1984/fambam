<?php

namespace App\Services;

use App\Enums\FamilyActivityType;
use App\Models\FamilyActivity;
use App\Models\PersonAccountLink;

class FamilyActivityRecorder
{
    /** @param list<string> $photoIds */
    public function record(
        string $familySpaceId,
        int $actorUserId,
        FamilyActivityType $type,
        ?string $subjectAlbumId = null,
        ?string $subjectEventId = null,
        ?string $subjectStoryId = null,
        ?string $subjectPersonId = null,
        ?string $contributionBatchId = null,
        array $photoIds = [],
    ): FamilyActivity {
        $actorPersonId = PersonAccountLink::query()
            ->where('family_space_id', $familySpaceId)
            ->where('user_id', $actorUserId)
            ->value('person_id');

        return FamilyActivity::query()->create([
            'family_space_id' => $familySpaceId,
            'actor_user_id' => $actorUserId,
            'actor_person_id' => $actorPersonId,
            'action_type' => $type,
            'subject_album_id' => $subjectAlbumId,
            'subject_event_id' => $subjectEventId,
            'subject_story_id' => $subjectStoryId,
            'subject_person_id' => $subjectPersonId,
            'contribution_batch_id' => $contributionBatchId,
            'photo_ids' => $photoIds === [] ? null : array_slice(array_unique($photoIds), 0, 12),
            'created_at' => now(),
        ]);
    }
}
