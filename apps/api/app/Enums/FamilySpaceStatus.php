<?php

namespace App\Enums;

enum FamilySpaceStatus: string
{
    case Active = 'active';
    case DeletionRequested = 'deletion_requested';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
}
