<?php

namespace App\Enums;

enum FamilyExportState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminalNotificationState(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}
