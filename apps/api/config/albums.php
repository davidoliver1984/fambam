<?php

return [
    // Album grids load in compact rows; keep both the initial payload and each
    // continuation bounded even when a client supplies its own limit.
    'default_page_size' => 12,
    'maximum_page_size' => 50,

    // There was no pre-existing Fambam freshness duration. Fourteen days is a
    // deliberately short, conservative window for the approved "New" badge.
    // Freshness is based only on the canonical Album created_at timestamp.
    'new_window_days' => 14,
];
