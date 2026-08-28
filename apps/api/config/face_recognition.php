<?php

return [
    'processing_enabled' => (bool) env('FACE_RECOGNITION_PROCESSING_ENABLED', false),
    'projection_version' => env('FACE_RECOGNITION_PROJECTION_VERSION', 'float32-le-v1'),
    'similarity_max_results' => (int) env('FACE_RECOGNITION_SIMILARITY_MAX_RESULTS', 100),
    // Intentionally unset until FPA-P10-S07 accepts a calibrated profile.
    'clustering_max_cosine_distance' => env('FACE_RECOGNITION_CLUSTERING_MAX_COSINE_DISTANCE'),
];
