"""Run the private benchmark directly against the local face provider."""

from __future__ import annotations

import argparse
import json
import platform
import statistics
import time
from collections import defaultdict
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import onnxruntime  # type: ignore[import-untyped]
import psutil  # type: ignore[import-untyped]

from app.face_analysis.contracts import FaceAnalysisResult
from app.face_analysis.insightface_provider import (
    InsightFaceProvider,
    InsightFaceSettings,
)


def run_benchmark(
    benchmark_root: Path,
    insightface_root: Path,
    *,
    detection_threshold: float,
) -> dict[str, Any]:
    annotations = json.loads(
        (benchmark_root / "annotations.json").read_text(encoding="utf-8")
    )
    assets = benchmark_root / "assets"

    process = psutil.Process()
    rss_before = process.memory_info().rss
    load_started = time.perf_counter()
    provider = InsightFaceProvider(
        InsightFaceSettings(
            insightface_root=insightface_root,
            detection_threshold=detection_threshold,
        )
    )
    load_seconds = time.perf_counter() - load_started
    rss_after_load = process.memory_info().rss

    first_asset = sorted(assets.glob(f"{sorted(annotations)[0]}.*"))[0]
    warmup_started = time.perf_counter()
    provider.analyze(first_asset)
    warmup_seconds = time.perf_counter() - warmup_started

    measurements: list[dict[str, Any]] = []
    rss_peak = process.memory_info().rss
    for benchmark_id, annotation in sorted(annotations.items()):
        matches = sorted(assets.glob(f"{benchmark_id}.*"))
        if len(matches) != 1:
            raise RuntimeError(f"expected exactly one asset for {benchmark_id}")

        expected_count = int(annotation["expected_face_count"])
        width, height = _image_dimensions(matches[0])
        started = time.perf_counter()
        try:
            faces = provider.analyze(matches[0])
            elapsed = time.perf_counter() - started
            result_bytes = len(
                FaceAnalysisResult(contract_version="1", faces=list(faces))
                .model_dump_json()
                .encode("utf-8")
            )
            confidences = [float(face.detection_confidence) for face in faces]
            detected_count = len(faces)
            measurements.append(
                {
                    "benchmark_id": benchmark_id,
                    "core": bool(annotation["core"]),
                    "categories": sorted(
                        str(value) for value in annotation["categories"]
                    ),
                    "status": "completed",
                    "width": width,
                    "height": height,
                    "expected_face_count": expected_count,
                    "detected_face_count": detected_count,
                    "matched_count_upper_bound": min(expected_count, detected_count),
                    "missed_count_lower_bound": max(expected_count - detected_count, 0),
                    "excess_detection_count": max(detected_count - expected_count, 0),
                    "exact_count": detected_count == expected_count,
                    "confidence": _distribution(confidences),
                    "latency_ms": round(elapsed * 1000, 3),
                    "result_bytes": result_bytes,
                }
            )
        except Exception as exception:
            elapsed = time.perf_counter() - started
            measurements.append(
                {
                    "benchmark_id": benchmark_id,
                    "core": bool(annotation["core"]),
                    "categories": sorted(
                        str(value) for value in annotation["categories"]
                    ),
                    "status": "failed",
                    "failure_category": exception.__class__.__name__,
                    "width": width,
                    "height": height,
                    "expected_face_count": expected_count,
                    "latency_ms": round(elapsed * 1000, 3),
                }
            )
        rss_peak = max(rss_peak, process.memory_info().rss)

    summary = summarize_measurements(measurements)

    return {
        "schema_version": 2,
        "privacy": "private-local-do-not-commit",
        "provider_identity": provider.identity.model_dump(mode="json"),
        "runtime": {
            "python": platform.python_version(),
            "platform": platform.platform(),
            "machine": platform.machine(),
            "onnxruntime": onnxruntime.__version__,
            "execution_providers": list(onnxruntime.get_available_providers()),
        },
        "model_load_ms": round(load_seconds * 1000, 3),
        "first_inference_warmup_ms": round(warmup_seconds * 1000, 3),
        "rss_before_bytes": rss_before,
        "rss_after_load_bytes": rss_after_load,
        "rss_peak_bytes": rss_peak,
        **summary,
        "measurements": measurements,
    }


def summarize_measurements(measurements: list[dict[str, Any]]) -> dict[str, Any]:
    """Produce aggregate acceptance evidence without biometric output values."""
    completed = [item for item in measurements if item["status"] == "completed"]
    failures = [item for item in measurements if item["status"] == "failed"]
    latencies = [float(item["latency_ms"]) for item in completed]
    result_sizes = [int(item["result_bytes"]) for item in completed]
    confidences = [
        float(value)
        for item in completed
        for value in item["confidence"].get("values", [])
    ]
    expected = sum(int(item["expected_face_count"]) for item in measurements)
    detected = sum(int(item["detected_face_count"]) for item in completed)
    matched_upper_bound = sum(
        int(item["matched_count_upper_bound"]) for item in completed
    )
    missed_lower_bound = sum(
        int(item["missed_count_lower_bound"]) for item in completed
    ) + sum(int(item["expected_face_count"]) for item in failures)
    excess = sum(int(item["excess_detection_count"]) for item in completed)

    by_category: dict[str, dict[str, int]] = defaultdict(
        lambda: {
            "images": 0,
            "completed": 0,
            "expected_face_count": 0,
            "detected_face_count": 0,
            "exact_count_images": 0,
            "missed_count_lower_bound": 0,
            "excess_detection_count": 0,
        }
    )
    for item in measurements:
        for category in item["categories"]:
            aggregate = by_category[str(category)]
            aggregate["images"] += 1
            aggregate["expected_face_count"] += int(item["expected_face_count"])
            if item["status"] == "completed":
                aggregate["completed"] += 1
                aggregate["detected_face_count"] += int(item["detected_face_count"])
                aggregate["exact_count_images"] += int(bool(item["exact_count"]))
                aggregate["missed_count_lower_bound"] += int(
                    item["missed_count_lower_bound"]
                )
                aggregate["excess_detection_count"] += int(
                    item["excess_detection_count"]
                )
            else:
                aggregate["missed_count_lower_bound"] += int(
                    item["expected_face_count"]
                )

    return {
        "image_count": len(measurements),
        "completed_image_count": len(completed),
        "failed_image_count": len(failures),
        "failure_rate": round(
            len(failures) / len(measurements) if measurements else 0.0, 6
        ),
        "expected_face_count": expected,
        "detected_face_count": detected,
        "matched_count_upper_bound": matched_upper_bound,
        "detection_coverage_upper_bound": round(
            matched_upper_bound / expected if expected else 1.0, 6
        ),
        "missed_count_lower_bound": missed_lower_bound,
        "excess_detection_count": excess,
        "exact_count_images": sum(int(bool(item["exact_count"])) for item in completed),
        "latency_ms": _distribution(latencies),
        "throughput_images_per_second": round(
            len(completed) / (sum(latencies) / 1000) if latencies else 0.0, 3
        ),
        "result_bytes": _distribution(result_sizes),
        "detection_confidence": _distribution(confidences, retain_values=False),
        "categories": dict(sorted(by_category.items())),
    }


def _distribution(
    values: list[float] | list[int], *, retain_values: bool = True
) -> dict[str, Any]:
    if not values:
        return {}
    result: dict[str, Any] = {
        "minimum": min(values),
        "median": round(statistics.median(values), 6),
        "p95": round(_percentile(values, 0.95), 6),
        "maximum": max(values),
    }
    if retain_values:
        result["values"] = values
    return result


def _image_dimensions(path: Path) -> tuple[int, int]:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), cv2.IMREAD_UNCHANGED)
    if image is None or image.ndim < 2:
        raise RuntimeError("benchmark asset could not be decoded")
    height, width = image.shape[:2]
    return int(width), int(height)


def _percentile(values: list[float] | list[int], percentile: float) -> float:
    ordered = sorted(float(value) for value in values)
    index = (len(ordered) - 1) * percentile
    lower = int(index)
    upper = min(lower + 1, len(ordered) - 1)
    fraction = index - lower
    return ordered[lower] + ((ordered[upper] - ordered[lower]) * fraction)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--benchmark-root", type=Path, required=True, help="Private benchmark root"
    )
    parser.add_argument(
        "--insightface-root",
        type=Path,
        required=True,
        help="Root containing models/buffalo_l",
    )
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--detection-threshold", type=float, default=0.6)
    args = parser.parse_args()

    summary = run_benchmark(
        args.benchmark_root,
        args.insightface_root,
        detection_threshold=args.detection_threshold,
    )
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print(
        f"Analyzed {summary['image_count']} private images; "
        f"detected {summary['detected_face_count']} faces."
    )
    print(f"Private summary: {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
