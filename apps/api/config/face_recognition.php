<?php

return [
    'processing_enabled' => (bool) env('FACE_RECOGNITION_PROCESSING_ENABLED', false),
    'projection_version' => env('FACE_RECOGNITION_PROJECTION_VERSION', 'float32-le-v1'),
    'similarity_max_results' => (int) env('FACE_RECOGNITION_SIMILARITY_MAX_RESULTS', 100),
    // Intentionally unset until FPA-P10-S07 accepts a calibrated profile.
    'clustering_max_cosine_distance' => env('FACE_RECOGNITION_CLUSTERING_MAX_COSINE_DISTANCE'),
    'suggestion_strong_max_distance' => env('FACE_RECOGNITION_SUGGESTION_STRONG_MAX_DISTANCE'),
    'suggestion_shortlist_max_distance' => env('FACE_RECOGNITION_SUGGESTION_SHORTLIST_MAX_DISTANCE'),
    'suggestion_ambiguity_margin' => env('FACE_RECOGNITION_SUGGESTION_AMBIGUITY_MARGIN'),
    'suggestion_minimum_strong_references' => env('FACE_RECOGNITION_SUGGESTION_MINIMUM_STRONG_REFERENCES'),
    'calibration_profile' => env('FACE_RECOGNITION_CALIBRATION_PROFILE'),
];
