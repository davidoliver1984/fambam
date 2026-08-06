<?php

namespace App\Enums;

enum RelationshipProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
