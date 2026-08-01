<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', static fn (): array => [
    'service' => 'api',
    'status' => 'ok',
]);
