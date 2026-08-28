<?php

$profiles = [
    'buffalo-l-v0.7-private-family-v1' => [
        'clustering_max_cosine_distance' => 0.350,
        'suggestion_strong_max_distance' => 0.350,
        'suggestion_shortlist_max_distance' => 0.685,
        'suggestion_ambiguity_margin' => 0.300,
        'suggestion_minimum_strong_references' => 2,
    ],
];
$calibrationProfile = env(
    'FACE_RECOGNITION_CALIBRATION_PROFILE',
    'buffalo-l-v0.7-private-family-v1',
);
$thresholds = is_string($calibrationProfile)
    ? ($profiles[$calibrationProfile] ?? [])
    : [];

return [
    'processing_enabled' => (bool) env('FACE_RECOGNITION_PROCESSING_ENABLED', false),
    'projection_version' => env('FACE_RECOGNITION_PROJECTION_VERSION', 'float32-le-v1'),
    'similarity_max_results' => (int) env('FACE_RECOGNITION_SIMILARITY_MAX_RESULTS', 100),
    'calibration_profile' => $calibrationProfile,
    'profiles' => $profiles,
    'clustering_max_cosine_distance' => $thresholds['clustering_max_cosine_distance'] ?? null,
    'suggestion_strong_max_distance' => $thresholds['suggestion_strong_max_distance'] ?? null,
    'suggestion_shortlist_max_distance' => $thresholds['suggestion_shortlist_max_distance'] ?? null,
    'suggestion_ambiguity_margin' => $thresholds['suggestion_ambiguity_margin'] ?? null,
    'suggestion_minimum_strong_references' => $thresholds['suggestion_minimum_strong_references'] ?? null,
];
