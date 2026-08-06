<?php

namespace App\Enums;

enum RelationshipStatus: string
{
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
}
