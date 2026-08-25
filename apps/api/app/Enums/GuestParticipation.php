<?php

namespace App\Enums;

enum GuestParticipation: string
{
    case None = 'none';
    case View = 'view';
    case Contribute = 'contribute';

    public function canView(): bool
    {
        return $this !== self::None;
    }

    public function canContribute(): bool
    {
        return $this === self::Contribute;
    }
}
