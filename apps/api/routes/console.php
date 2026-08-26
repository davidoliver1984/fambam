<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fambam:dispatch-due-family-space-deletions')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('fambam:dispatch-due-media-quarantine-purges')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('fambam:dispatch-due-abandoned-media-uploads')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('fambam:dispatch-due-event-exports')
    ->hourly()
    ->withoutOverlapping();
