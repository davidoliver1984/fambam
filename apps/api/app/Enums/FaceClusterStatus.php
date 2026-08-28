<?php

namespace App\Enums;

enum FaceClusterStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
    case Superseded = 'superseded';
}
