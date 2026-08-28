<?php

namespace App\FaceRecognition;

use App\Enums\FaceIdentityAssignmentStatus;
use App\Models\FaceIdentityAssignment;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RecognitionConsentManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function set(Person $person, bool $allowed, User $actor, Request $request): Person
    {
        return DB::transaction(function () use ($person, $allowed, $actor, $request): Person {
            $this->authorize($actor);
            $locked = Person::query()->lockForUpdate()->findOrFail($person->id);
            if ($locked->family_space_id !== $this->tenant->familySpace()->id) {
                throw new AuthorizationException;
            }
            if ($locked->recognition_allowed === $allowed) {
                return $locked;
            }

            $locked->update(['recognition_allowed' => $allowed]);
            $withdrawnCount = 0;
            if (! $allowed) {
                $withdrawnCount = FaceIdentityAssignment::query()
                    ->where('person_id', $locked->id)
                    ->where('status', FaceIdentityAssignmentStatus::Pending)
                    ->update([
                        'status' => FaceIdentityAssignmentStatus::Withdrawn,
                        'resolved_by' => $actor->id,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $this->audit->record('person.recognition_consent_changed', $locked, $actor, $request, [
                'recognition_allowed' => $allowed,
                'withdrawn_assignment_count' => $withdrawnCount,
            ]);

            return $locked;
        });
    }

    private function authorize(User $actor): void
    {
        $membership = $this->tenant->membership();
        if ($membership->user_id !== $actor->id || ! $membership->role->canManageMembers()) {
            throw new AuthorizationException;
        }
    }
}
