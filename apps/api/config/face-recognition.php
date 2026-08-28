<?php

return [
    'projection_version' => env('FACE_RECOGNITION_PROJECTION_VERSION', 'float32-le-v1'),
    'similarity_max_results' => (int) env('FACE_RECOGNITION_SIMILARITY_MAX_RESULTS', 100),
];
