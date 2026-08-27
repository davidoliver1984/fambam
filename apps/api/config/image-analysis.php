<?php

return [
    'contract_version' => '1',
    'identity' => [
        'provider' => env('FACE_ANALYSIS_PROVIDER', 'insightface-onnxruntime'),
        'model_identifier' => env('FACE_ANALYSIS_MODEL_IDENTIFIER', 'buffalo_l-v0.7'),
        'model_weight_checksum' => env('FACE_ANALYSIS_MODEL_WEIGHT_CHECKSUM', '80ffe37d8a5940d59a7384c201a2a38d4741f2f3c51eef46ebb28218a7b0ca2f'),
        'config_hash' => env('FACE_ANALYSIS_CONFIG_HASH', '9311c040d0047b0f3568371d6a1b4b0213a37c631906276e6120de95af9b5964'),
    ],
    'queues' => [
        'requested' => env('FACE_ANALYSIS_REQUESTED_QUEUE', 'image-analysis-requested'),
        'completed' => env('FACE_ANALYSIS_COMPLETED_QUEUE', 'image-analysis-completed'),
        'failed' => env('FACE_ANALYSIS_FAILED_QUEUE', 'image-analysis-failed'),
        'synthetic' => env('IMAGE_ANALYSIS_SYNTHETIC_QUEUE', 'image-analysis-synthetic'),
        'visibility_timeout_seconds' => (int) env('FACE_ANALYSIS_VISIBILITY_TIMEOUT_SECONDS', 30),
        'max_receive_count' => (int) env('FACE_ANALYSIS_MAX_RECEIVE_COUNT', 5),
        'wait_time_seconds' => (int) env('FACE_ANALYSIS_WAIT_TIME_SECONDS', 10),
    ],
    'authority_ttl_minutes' => (int) env('FACE_ANALYSIS_AUTHORITY_TTL_MINUTES', 60),
    'attempt_stale_minutes' => (int) env('FACE_ANALYSIS_ATTEMPT_STALE_MINUTES', 5),
    'max_attempts_per_run' => (int) env('FACE_ANALYSIS_MAX_ATTEMPTS_PER_RUN', 3),
    'result' => [
        'max_bytes' => (int) env('FACE_ANALYSIS_RESULT_MAX_BYTES', 4 * 1024 * 1024),
        'max_faces' => (int) env('FACE_ANALYSIS_RESULT_MAX_FACES', 256),
        'max_json_depth' => (int) env('FACE_ANALYSIS_RESULT_MAX_JSON_DEPTH', 8),
        'max_provider_diagnostics_bytes' => (int) env('FACE_ANALYSIS_PROVIDER_DIAGNOSTICS_MAX_BYTES', 16 * 1024),
        'landmark_schemes' => [
            '5-point' => 5,
        ],
        'embedding_shapes' => [
            ['dimension' => 512, 'dtype' => 'float32'],
        ],
    ],
];
