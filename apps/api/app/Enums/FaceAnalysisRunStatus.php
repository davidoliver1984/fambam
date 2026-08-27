<?php

namespace App\Enums;

enum FaceAnalysisRunStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
