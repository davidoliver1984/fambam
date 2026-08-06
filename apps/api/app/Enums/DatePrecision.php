<?php

namespace App\Enums;

enum DatePrecision: string
{
    case Exact = 'exact';
    case Month = 'month';
    case Year = 'year';
    case Decade = 'decade';
    case Approximate = 'approximate';
    case Unknown = 'unknown';
}
