<?php

namespace App\Enums;

enum PersonMergeProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
