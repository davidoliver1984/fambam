"""Compare logical face-analysis output across two ONNX execution backends."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import numpy as np

from app.face_analysis.contracts import DetectedFace
from app.face_analysis.insightface_provider import (
    InsightFaceProvider,
    InsightFaceSettings,
)


def compare_backends(assets: list[Path], insightface_root: Path) -> dict[str, Any]:
    cpu = InsightFaceProvider(
        InsightFaceSettings(
            insightface_root=insightface_root,
            execution_providers=("CPUExecutionProvider",),
        )
    )
    accelerated = InsightFaceProvider(
        InsightFaceSettings(
            insightface_root=insightface_root,
            execution_providers=(
                "CoreMLExecutionProvider",
                "CPUExecutionProvider",
            ),
        )
    )
    if cpu.identity.config_hash != accelerated.identity.config_hash:
        raise RuntimeError("execution backend changed the logical config hash")

    comparisons: list[dict[str, Any]] = []
    passed = True
    for asset in assets:
        cpu_faces = cpu.analyze(asset)
        accelerated_faces = accelerated.analyze(asset)
        same_count = len(cpu_faces) == len(accelerated_faces)
        face_comparisons = [
            _compare_face(cpu_face, accelerated_face)
            for cpu_face, accelerated_face in zip(
                cpu_faces, accelerated_faces, strict=False
            )
        ]
        asset_passed = same_count and all(
            bool(comparison["within_tolerance"]) for comparison in face_comparisons
        )
        passed = passed and asset_passed
        comparisons.append(
            {
                "asset_id": asset.stem,
                "cpu_face_count": len(cpu_faces),
                "accelerated_face_count": len(accelerated_faces),
                "faces": face_comparisons,
                "within_tolerance": asset_passed,
            }
        )

    return {
        "schema_version": 1,
        "privacy": "private-local-do-not-commit",
        "config_hash": cpu.identity.config_hash,
        "cpu_providers": list(cpu.settings.execution_providers),
        "accelerated_providers": list(accelerated.settings.execution_providers),
        "comparisons": comparisons,
        "within_tolerance": passed,
    }


def _compare_face(cpu: DetectedFace, accelerated: DetectedFace) -> dict[str, Any]:
    cpu_bounds = np.asarray(
        [cpu.bounds.x, cpu.bounds.y, cpu.bounds.width, cpu.bounds.height]
    )
    accelerated_bounds = np.asarray(
        [
            accelerated.bounds.x,
            accelerated.bounds.y,
            accelerated.bounds.width,
            accelerated.bounds.height,
        ]
    )
    cpu_landmarks = np.asarray([[point.x, point.y] for point in cpu.landmarks])
    accelerated_landmarks = np.asarray(
        [[point.x, point.y] for point in accelerated.landmarks]
    )
    cpu_embedding = np.asarray(cpu.embedding)
    accelerated_embedding = np.asarray(accelerated.embedding)
    cosine = float(
        np.dot(cpu_embedding, accelerated_embedding)
        / (np.linalg.norm(cpu_embedding) * np.linalg.norm(accelerated_embedding))
    )
    bounds_delta = float(np.max(np.abs(cpu_bounds - accelerated_bounds)))
    landmark_delta = float(np.max(np.abs(cpu_landmarks - accelerated_landmarks)))
    confidence_delta = abs(cpu.detection_confidence - accelerated.detection_confidence)
    embedding_delta = float(np.max(np.abs(cpu_embedding - accelerated_embedding)))
    within_tolerance = (
        bounds_delta <= 1.0
        and landmark_delta <= 1.0
        and confidence_delta <= 0.01
        and cosine >= 0.999
    )
    return {
        "bounds_max_abs_delta": bounds_delta,
        "landmarks_max_abs_delta": landmark_delta,
        "confidence_abs_delta": confidence_delta,
        "embedding_max_abs_delta": embedding_delta,
        "embedding_cosine": cosine,
        "within_tolerance": within_tolerance,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--asset", action="append", type=Path, required=True)
    parser.add_argument("--insightface-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    result = compare_backends(args.asset, args.insightface_root)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print(
        "Backend parity passed."
        if result["within_tolerance"]
        else "Backend parity failed."
    )
    print(f"Private summary: {args.output}")
    return 0 if result["within_tolerance"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
