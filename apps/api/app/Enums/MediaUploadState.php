<?php

namespace App\Enums;

enum MediaUploadState: string
{
    case Initiated = 'initiated';
    case Uploaded = 'uploaded';
    case Verifying = 'verifying';
    case Preserved = 'preserved';
    case Processing = 'processing';
    case Ready = 'ready';
    case Quarantined = 'quarantined';
    case Abandoned = 'abandoned';
    case Degraded = 'degraded';
}
