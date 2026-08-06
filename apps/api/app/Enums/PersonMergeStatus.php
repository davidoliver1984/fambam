<?php

namespace App\Enums;

enum PersonMergeStatus: string
{
    case Active = 'active';
    case Reversed = 'reversed';
    case ManualCorrectionRequired = 'manual_correction_required';
}
