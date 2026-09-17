<?php

namespace App\Enums;

enum NotificationOutcome: string
{
    case Pending = 'pending';
    case Created = 'created';
    case SkippedPreference = 'skipped_preference';
    case SkippedAuthorization = 'skipped_authorization';
    case SkippedNoActors = 'skipped_no_actors';
}
