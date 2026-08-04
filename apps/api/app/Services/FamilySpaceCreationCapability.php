<?php

namespace App\Services;

use App\Models\User;

class FamilySpaceCreationCapability
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function set(User $user, bool $enabled, string $operatorReference): void
    {
        if ($user->can_create_family_spaces === $enabled) {
            return;
        }

        $user->forceFill(['can_create_family_spaces' => $enabled])->save();
        $this->audit->record(
            $enabled ? 'family_space.creation_granted' : 'family_space.creation_revoked',
            $user,
            metadata: [
                'actor_type' => 'console_operator',
                'operator_reference' => $operatorReference,
            ],
        );
    }
}
