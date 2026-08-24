<?php

namespace App\Enums;

enum PhotoReactionType: string
{
    case Love = 'love';
    case Smile = 'smile';
    case Laugh = 'laugh';
    case Remember = 'remember';
}
