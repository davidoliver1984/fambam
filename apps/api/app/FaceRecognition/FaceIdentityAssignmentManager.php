<?php

namespace App\FaceRecognition;

use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\PersonProposalStatus;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Services\AuditRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FaceIdentityAssignmentManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PhotoPolicy $photos,
        private readonly AuditRecorder $audit,
    ) {}

    public function propose(
        FaceObservation $observation,
        Person $person,
        User $actor,
        Request $request,
    ): FaceIdentityAssignment {
        return DB::transaction(function () use ($observation, $person, $actor, $request): FaceIdentityAssignment {
            $this->authorizeProposal($actor);
            $lockedObservation = FaceObservation::query()->lockForUpdate()->findOrFail($observation->id);
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
            if ($lockedObservation->family_space_id !== $lockedPerson->family_space_id
                || $lockedObservation->family_space_id !== $this->tenant->familySpace()->id) {
                throw new AuthorizationException;
            }
            $photo = $this->photoFor($lockedObservation);
            if (! $this->photos->view($actor, $photo)) {
                throw new AuthorizationException;
            }
            if (FaceIdentityAssignment::query()->where('face_observation_id', $lockedObservation->id)
                ->whereIn('status', [
                    FaceIdentityAssignmentStatus::Pending,
                    FaceIdentityAssignmentStatus::Approved,
                ])->exists()) {
                throw ValidationException::withMessages([
                    'face_observation' => ['This face already has an active identity claim.'],
                ]);
            }

            $assignment = FaceIdentityAssignment::query()->create([
                'family_space_id' => $lockedObservation->family_space_id,
                'face_observation_id' => $lockedObservation->id,
                'person_id' => $lockedPerson->id,
                'proposal_source' => 'human',
                'status' => FaceIdentityAssignmentStatus::Pending,
                'proposed_by' => $actor->id,
            ]);
            $this->audit->record('face_identity_assignment.proposed', $assignment, $actor, $request, [
                'face_observation_id' => $lockedObservation->id,
                'person_id' => $lockedPerson->id,
            ]);

            return $assignment;
        });
    }

    public function approve(
        FaceIdentityAssignment $assignment,
        User $actor,
        Request $request,
    ): FaceIdentityAssignment {
        return DB::transaction(function () use ($assignment, $actor, $request): FaceIdentityAssignment {
            $this->authorizeResolution($actor);
            $locked = FaceIdentityAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->family_space_id !== $this->tenant->familySpace()->id
                || ! in_array($locked->status, [
                    FaceIdentityAssignmentStatus::Pending,
                    FaceIdentityAssignmentStatus::Approved,
                ], true)) {
                throw ValidationException::withMessages([
                    'assignment' => ['This face identity assignment can no longer be approved.'],
                ]);
            }

            $observation = FaceObservation::query()->lockForUpdate()->findOrFail($locked->face_observation_id);
            $photo = $this->photoFor($observation);
            $photoPerson = $this->ensurePhotoPerson($photo, $locked, $actor, $request);
            if ($locked->status === FaceIdentityAssignmentStatus::Pending) {
                $locked->update([
                    'status' => FaceIdentityAssignmentStatus::Approved,
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ]);
                $this->audit->record('face_identity_assignment.approved', $locked, $actor, $request, [
                    'face_observation_id' => $locked->face_observation_id,
                    'person_id' => $locked->person_id,
                    'photo_id' => $photo->id,
                    'photo_person_id' => $photoPerson->id,
                ]);
            }

            return $locked;
        });
    }

    private function ensurePhotoPerson(
        Photo $photo,
        FaceIdentityAssignment $assignment,
        User $actor,
        Request $request,
    ): PhotoPerson {
        $active = PhotoPerson::query()
            ->where('photo_id', $photo->id)
            ->where('person_id', $assignment->person_id)
            ->whereIn('status', [PersonProposalStatus::Pending, PersonProposalStatus::Approved])
            ->lockForUpdate()
            ->first();
        if ($active?->status === PersonProposalStatus::Approved) {
            return $active;
        }
        if ($active?->status === PersonProposalStatus::Pending) {
            $active->update([
                'status' => PersonProposalStatus::Approved,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $this->auditPhotoPerson($active, $photo, $actor, $request);

            return $active;
        }

        $created = PhotoPerson::query()->create([
            'family_space_id' => $photo->family_space_id,
            'photo_id' => $photo->id,
            'person_id' => $assignment->person_id,
            'proposal_source' => 'face_identity_assignment',
            'status' => PersonProposalStatus::Approved,
            'proposed_by' => $assignment->proposed_by,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);
        $this->auditPhotoPerson($created, $photo, $actor, $request);

        return $created;
    }

    private function auditPhotoPerson(PhotoPerson $association, Photo $photo, User $actor, Request $request): void
    {
        $this->audit->record('photo.person_confirmed', $association, $actor, $request, [
            'photo_id' => $photo->id,
            'person_id' => $association->person_id,
        ]);
    }

    private function photoFor(FaceObservation $observation): Photo
    {
        $photo = Photo::query()
            ->join('face_analysis_runs', function ($join): void {
                $join->on('face_analysis_runs.media_upload_id', '=', 'photos.media_upload_id')
                    ->on('face_analysis_runs.family_space_id', '=', 'photos.family_space_id');
            })
            ->where('face_analysis_runs.id', $observation->face_analysis_run_id)
            ->where('photos.family_space_id', $observation->family_space_id)
            ->select('photos.*')
            ->lockForUpdate()
            ->first();
        if ($photo === null) {
            throw ValidationException::withMessages([
                'face_observation' => ['A face can be assigned only after its MediaUpload is promoted to a Photo.'],
            ]);
        }

        return $photo;
    }

    private function authorizeProposal(User $actor): void
    {
        $membership = $this->tenant->membership();
        if ($membership->user_id !== $actor->id || ! in_array($membership->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeResolution(User $actor): void
    {
        $membership = $this->tenant->membership();
        if ($membership->user_id !== $actor->id || ! $membership->role->canManageMembers()) {
            throw new AuthorizationException;
        }
    }
}
