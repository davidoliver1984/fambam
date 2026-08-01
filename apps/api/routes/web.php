<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static fn (): array => [
    'service' => 'api',
]);
