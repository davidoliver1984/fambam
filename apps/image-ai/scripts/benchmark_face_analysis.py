"""Run the private benchmark directly against the local face provider."""

from __future__ import annotations

import argparse
import json
import platform
import statistics
import time
from pathlib import Path
from typing import Any

import onnxruntime
import psutil

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

        started = time.perf_counter()
        faces = provider.analyze(matches[0])
        elapsed = time.perf_counter() - started
        result_bytes = len(
            FaceAnalysisResult(contract_version="1", faces=list(faces))
            .model_dump_json()
            .encode("utf-8")
        )
        measurements.append(
            {
                "benchmark_id": benchmark_id,
                "core": bool(annotation["core"]),
                "expected_face_count": int(annotation["expected_face_count"]),
                "detected_face_count": len(faces),
                "latency_ms": round(elapsed * 1000, 3),
                "result_bytes": result_bytes,
            }
        )
        rss_peak = max(rss_peak, process.memory_info().rss)

    latencies = [float(item["latency_ms"]) for item in measurements]
    result_sizes = [int(item["result_bytes"]) for item in measurements]
    expected = sum(int(item["expected_face_count"]) for item in measurements)
    detected = sum(int(item["detected_face_count"]) for item in measurements)

    return {
        "schema_version": 1,
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
        "image_count": len(measurements),
        "expected_face_count": expected,
        "detected_face_count": detected,
        "latency_ms": {
            "minimum": min(latencies),
            "median": round(statistics.median(latencies), 3),
            "p95": round(_percentile(latencies, 0.95), 3),
            "maximum": max(latencies),
        },
        "result_bytes": {
            "minimum": min(result_sizes),
            "median": round(statistics.median(result_sizes), 3),
            "p95": round(_percentile(result_sizes, 0.95), 3),
            "maximum": max(result_sizes),
        },
        "measurements": measurements,
    }


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
