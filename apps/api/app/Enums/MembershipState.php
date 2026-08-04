<?php

namespace App\Enums;

enum MembershipState: string
{
    case Active = 'active';
    case Removed = 'removed';
}
