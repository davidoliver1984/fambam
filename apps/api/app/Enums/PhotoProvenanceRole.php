<?php

namespace App\Enums;

enum PhotoProvenanceRole: string
{
    case Photographer = 'photographer';
    case Scanner = 'scanner';
    case PhysicalOwner = 'physical_owner';
}
