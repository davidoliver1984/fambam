<?php

return [
    'queue' => env('IMAGE_ANALYSIS_QUEUE', 'image-analysis-requested'),
    'contract_version' => '1',
    'result' => [
        'max_bytes' => (int) env('FACE_ANALYSIS_RESULT_MAX_BYTES', 8 * 1024 * 1024),
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
