<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Comment = 'comment';
    case Contribution = 'contribution';
    case Story = 'story';
    case Identity = 'identity';
}
