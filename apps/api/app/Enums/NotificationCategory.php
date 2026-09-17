<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Comment = 'comment';
    case Contribution = 'contribution';
    case Story = 'story';
    case Identity = 'identity';
    case Export = 'export';
    case Attendance = 'attendance';
    case Love = 'love';

    /** @return list<self> */
    public static function preferenceCases(): array
    {
        return [self::Comment, self::Contribution, self::Story, self::Identity, self::Attendance, self::Love];
    }
}
