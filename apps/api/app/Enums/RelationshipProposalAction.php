<?php

namespace App\Enums;

enum RelationshipProposalAction: string
{
    case Create = 'create';
    case Replace = 'replace';
    case Remove = 'remove';
    case Dispute = 'dispute';
}
