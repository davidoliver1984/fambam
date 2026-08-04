<?php

namespace App\Enums;

enum FamilySpaceRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Member = 'member';
    case Contributor = 'contributor';
    case Guest = 'guest';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Administrator;
    }
}
