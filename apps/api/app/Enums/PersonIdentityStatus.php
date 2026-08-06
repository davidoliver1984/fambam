<?php

namespace App\Enums;

enum PersonIdentityStatus: string
{
    case Provisional = 'provisional';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
}
