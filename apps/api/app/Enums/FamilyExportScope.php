<?php

namespace App\Enums;

enum FamilyExportScope: string
{
    case FamilySpaceFull = 'family_space_full';
    case Personal = 'personal';
    case Collection = 'collection';
    case Album = 'album';
}
