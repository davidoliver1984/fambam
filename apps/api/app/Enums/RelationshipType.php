<?php

namespace App\Enums;

enum RelationshipType: string
{
    case ParentOf = 'parent_of';
    case PartnerOf = 'partner_of';
    case SiblingOf = 'sibling_of';
    case GuardianOf = 'guardian_of';
    case StepParentOf = 'step_parent_of';
    case GrandparentOf = 'grandparent_of';
    case CloseFamilyFriendOf = 'close_family_friend_of';

    public function isSymmetric(): bool
    {
        return match ($this) {
            self::PartnerOf, self::SiblingOf, self::CloseFamilyFriendOf => true,
            default => false,
        };
    }

    public function forwardLabel(): string
    {
        return match ($this) {
            self::ParentOf => 'parent',
            self::PartnerOf => 'partner',
            self::SiblingOf => 'sibling',
            self::GuardianOf => 'guardian',
            self::StepParentOf => 'step-parent',
            self::GrandparentOf => 'grandparent',
            self::CloseFamilyFriendOf => 'close family friend',
        };
    }

    public function inverseLabel(): string
    {
        return match ($this) {
            self::ParentOf => 'child',
            self::GuardianOf => 'ward',
            self::StepParentOf => 'step-child',
            self::GrandparentOf => 'grandchild',
            default => $this->forwardLabel(),
        };
    }
}
