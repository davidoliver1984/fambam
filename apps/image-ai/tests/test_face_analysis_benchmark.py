from typing import Any

from scripts.benchmark_face_analysis import summarize_measurements


def test_benchmark_summary_reports_coverage_excess_failures_and_categories() -> None:
    measurements: list[dict[str, Any]] = [
        {
            "benchmark_id": "B001",
            "core": True,
            "categories": ["clear-frontal"],
            "status": "completed",
            "expected_face_count": 2,
            "detected_face_count": 3,
            "matched_count_upper_bound": 2,
            "missed_count_lower_bound": 0,
            "excess_detection_count": 1,
            "exact_count": False,
            "confidence": {"values": [0.9, 0.8, 0.7]},
            "latency_ms": 100.0,
            "result_bytes": 200,
        },
        {
            "benchmark_id": "B002",
            "core": True,
            "categories": ["poor-lighting"],
            "status": "completed",
            "expected_face_count": 2,
            "detected_face_count": 1,
            "matched_count_upper_bound": 1,
            "missed_count_lower_bound": 1,
            "excess_detection_count": 0,
            "exact_count": False,
            "confidence": {"values": [0.6]},
            "latency_ms": 300.0,
            "result_bytes": 100,
        },
        {
            "benchmark_id": "B003",
            "core": False,
            "categories": ["rotation"],
            "status": "failed",
            "failure_category": "InferenceError",
            "expected_face_count": 1,
            "latency_ms": 50.0,
        },
    ]

    summary = summarize_measurements(measurements)

    assert summary["completed_image_count"] == 2
    assert summary["failed_image_count"] == 1
    assert summary["failure_rate"] == 0.333333
    assert summary["detected_face_count"] == 4
    assert summary["matched_count_upper_bound"] == 3
    assert summary["detection_coverage_upper_bound"] == 0.6
    assert summary["missed_count_lower_bound"] == 2
    assert summary["excess_detection_count"] == 1
    assert summary["latency_ms"]["median"] == 200.0
    assert summary["throughput_images_per_second"] == 5.0
    assert summary["categories"]["rotation"]["completed"] == 0


def test_benchmark_summary_contains_no_detection_coordinates_or_embeddings() -> None:
    summary = summarize_measurements(
        [
            {
                "benchmark_id": "B001",
                "core": True,
                "categories": ["clear-frontal"],
                "status": "completed",
                "expected_face_count": 1,
                "detected_face_count": 1,
                "matched_count_upper_bound": 1,
                "missed_count_lower_bound": 0,
                "excess_detection_count": 0,
                "exact_count": True,
                "confidence": {"values": [0.9]},
                "latency_ms": 10.0,
                "result_bytes": 100,
            }
        ]
    )

    rendered = str(summary).lower()
    assert "embedding" not in rendered
    assert "landmark" not in rendered
    assert "bounds" not in rendered
