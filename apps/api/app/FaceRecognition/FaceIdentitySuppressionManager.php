<?php

namespace App\FaceRecognition;

use App\Enums\FaceIdentityAssignmentStatus;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceIdentitySuppression;
use App\Models\FaceObservation;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FaceIdentitySuppressionManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function rejectAssignment(
        FaceIdentityAssignment $assignment,
        User $actor,
        Request $request,
    ): FaceIdentitySuppression {
        return DB::transaction(function () use ($assignment, $actor, $request): FaceIdentitySuppression {
            $this->authorize($actor);
            $locked = FaceIdentityAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->family_space_id !== $this->tenant->familySpace()->id) {
                throw new AuthorizationException;
            }
            if ($locked->status !== FaceIdentityAssignmentStatus::Pending) {
                throw ValidationException::withMessages(['assignment' => ['Only a pending suggestion may be rejected.']]);
            }
            $locked->update([
                'status' => FaceIdentityAssignmentStatus::Rejected,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $suppression = $this->suppress($locked->face_observation_id, $locked->person_id, $actor);
            $this->audit->record('face_identity_assignment.rejected', $locked, $actor, $request, [
                'face_observation_id' => $locked->face_observation_id,
                'person_id' => $locked->person_id,
                'suppression_id' => $suppression->id,
            ]);

            return $suppression;
        });
    }

    public function rejectCandidate(
        FaceObservation $observation,
        Person $person,
        User $actor,
        Request $request,
    ): FaceIdentitySuppression {
        return DB::transaction(function () use ($observation, $person, $actor, $request): FaceIdentitySuppression {
            $this->authorize($actor);
            $lockedObservation = FaceObservation::query()->lockForUpdate()->findOrFail($observation->id);
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
            if ($lockedObservation->family_space_id !== $lockedPerson->family_space_id
                || $lockedObservation->family_space_id !== $this->tenant->familySpace()->id) {
                throw new AuthorizationException;
            }
            $suppression = $this->suppress($lockedObservation->id, $lockedPerson->id, $actor);
            $this->audit->record('face_identity_suppression.decided', $suppression, $actor, $request);

            return $suppression;
        });
    }

    public function reopen(FaceIdentitySuppression $suppression, User $actor, Request $request): FaceIdentitySuppression
    {
        return DB::transaction(function () use ($suppression, $actor, $request): FaceIdentitySuppression {
            $this->authorize($actor);
            $locked = FaceIdentitySuppression::query()->lockForUpdate()->findOrFail($suppression->id);
            if ($locked->family_space_id !== $this->tenant->familySpace()->id) {
                throw new AuthorizationException;
            }
            if ($locked->reopened_at !== null) {
                return $locked;
            }
            $locked->update(['reopened_by' => $actor->id, 'reopened_at' => now()]);
            $this->audit->record('face_identity_suppression.reopened', $locked, $actor, $request);

            return $locked;
        });
    }

    private function suppress(string $observationId, string $personId, User $actor): FaceIdentitySuppression
    {
        $suppression = FaceIdentitySuppression::query()->firstOrNew([
            'family_space_id' => $this->tenant->familySpace()->id,
            'face_observation_id' => $observationId,
            'person_id' => $personId,
        ]);
        $suppression->fill([
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'reopened_by' => null,
            'reopened_at' => null,
        ]);
        $suppression->save();

        return $suppression;
    }

    private function authorize(User $actor): void
    {
        $membership = $this->tenant->membership();
        if ($membership->user_id !== $actor->id || ! $membership->role->canManageMembers()) {
            throw new AuthorizationException;
        }
    }
}
