<?php

return [
    // Public, non-secret Argon2id hash used only to equalise missing-user verification work.
    'dummy_password_hash' => env(
        'DUMMY_PASSWORD_HASH',
        '$argon2id$v=19$m=65536,t=4,p=1$Q0dkOVYvRWtOa0FNeXg5Rw$eZ/KKeLK5sqmpfDR8rONzlXnPMYgajkyZuA0Rp/slpY',
    ),
    'compromised_password_check' => [
        'enabled' => env('COMPROMISED_PASSWORD_CHECK_ENABLED', true),
        'timeout_seconds' => (float) env('COMPROMISED_PASSWORD_CHECK_TIMEOUT', 1.5),
    ],
];
