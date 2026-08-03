<?php

return [
    'compromised_password_check' => [
        'enabled' => env('COMPROMISED_PASSWORD_CHECK_ENABLED', true),
        'timeout_seconds' => (float) env('COMPROMISED_PASSWORD_CHECK_TIMEOUT', 1.5),
    ],
];
