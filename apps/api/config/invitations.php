<?php

return [
    'lifetime_days' => (int) env('INVITATION_LIFETIME_DAYS', 7),
    'claim_lifetime_minutes' => (int) env('INVITATION_CLAIM_LIFETIME_MINUTES', 15),
];
