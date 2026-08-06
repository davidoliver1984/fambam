<?php

namespace App\Enums;

enum PersonAccountClaimStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
