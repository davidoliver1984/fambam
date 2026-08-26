<?php

namespace App\Enums;

enum DuplicateResolution: string
{
    case UseExisting = 'use_existing';
    case CreateNew = 'create_new';
    case Cancel = 'cancel';
}
