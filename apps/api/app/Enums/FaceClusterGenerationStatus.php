<?php

namespace App\Enums;

enum FaceClusterGenerationStatus: string
{
    case Building = 'building';
    case Active = 'active';
    case Superseded = 'superseded';
}
