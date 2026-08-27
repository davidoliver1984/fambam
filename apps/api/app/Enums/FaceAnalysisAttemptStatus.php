<?php

namespace App\Enums;

enum FaceAnalysisAttemptStatus: string
{
    case Dispatched = 'dispatched';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
