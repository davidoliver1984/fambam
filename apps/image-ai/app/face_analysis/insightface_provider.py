"""Pinned InsightFace implementation of the provider-neutral face contract."""

from __future__ import annotations

import hashlib
import json
import math
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Protocol, cast

import cv2
import numpy as np

from app.face_analysis.contracts import (
    AnalysisIdentity,
    Bounds,
    DetectedFace,
    LandmarkPoint,
)

BUFFALO_L_ARCHIVE_SHA256 = (
    "80ffe37d8a5940d59a7384c201a2a38d4741f2f3c51eef46ebb28218a7b0ca2f"
)
BUFFALO_L_MODEL_CHECKSUMS = {
    "1k3d68.onnx": ("df5c06b8a0c12e422b2ed8947b8869faa4105387f199c477af038aa01f9a45cc"),
    "2d106det.onnx": (
        "f001b856447c413801ef5c42091ed0cd516fcd21f2d6b79635b1e733a7109dbf"
    ),
    "det_10g.onnx": (
        "5838f7fe053675b1c7a08b633df49e7af5495cee0493c7dcf6697200b85b5b91"
    ),
    "genderage.onnx": (
        "4fde69b1c810857b88c64a335084f1c3fe8f01246c9a191b48c7bb756d6652fb"
    ),
    "w600k_r50.onnx": (
        "4c06341c33c2ca1f86781dab0e829f88ad5b64be9fba56e56bc9ebdefc619e43"
    ),
}


class InsightFaceConfigurationError(RuntimeError):
    """Raised when pinned model files or configuration are invalid."""


class InsightFaceInferenceError(RuntimeError):
    """Raised when a canonical asset cannot be decoded or analyzed safely."""


class _FaceAnalyzer(Protocol):
    def get(self, image: np.ndarray[Any, Any], max_num: int = 0) -> Sequence[Any]: ...


@dataclass(frozen=True, slots=True)
class InsightFaceSettings:
    """Logical analysis configuration plus execution-only runtime settings."""

    insightface_root: Path
    model_name: str = "buffalo_l"
    model_identifier: str = "buffalo_l-v0.7"
    detection_threshold: float = 0.6
    detection_size: tuple[int, int] = (640, 640)
    max_faces: int = 256
    execution_providers: tuple[str, ...] = ("CPUExecutionProvider",)

    def __post_init__(self) -> None:
        if not 0 <= self.detection_threshold <= 1:
            raise ValueError("detection_threshold must be between zero and one")
        if any(size <= 0 for size in self.detection_size):
            raise ValueError("detection_size values must be positive")
        if self.max_faces < 1:
            raise ValueError("max_faces must be positive")
        if not self.execution_providers:
            raise ValueError("at least one execution provider is required")

    @property
    def model_directory(self) -> Path:
        return self.insightface_root / "models" / self.model_name

    @property
    def config_hash(self) -> str:
        """Hash logical output configuration, deliberately excluding backend."""

        logical_config = {
            "contract_version": "1",
            "detection_size": list(self.detection_size),
            "detection_threshold": self.detection_threshold,
            "embedding_normalization": "l2",
            "landmark_scheme": "5-point",
            "max_faces": self.max_faces,
        }
        encoded = json.dumps(
            logical_config, separators=(",", ":"), sort_keys=True
        ).encode("utf-8")
        return hashlib.sha256(encoded).hexdigest()


class InsightFaceProvider:
    """Run pinned buffalo_l detection and embeddings through ONNX Runtime."""

    def __init__(
        self,
        settings: InsightFaceSettings,
        *,
        analyzer: _FaceAnalyzer | None = None,
        verify_model_files: bool = True,
    ) -> None:
        self.settings = settings
        if verify_model_files:
            self._verify_model_files()
        self._analyzer = analyzer or self._build_analyzer()

    @property
    def identity(self) -> AnalysisIdentity:
        return AnalysisIdentity(
            provider="insightface-onnxruntime",
            model_identifier=self.settings.model_identifier,
            model_weight_checksum=BUFFALO_L_ARCHIVE_SHA256,
            config_hash=self.settings.config_hash,
        )

    def analyze(self, canonical_path: Path) -> tuple[DetectedFace, ...]:
        image = cv2.imdecode(
            np.fromfile(canonical_path, dtype=np.uint8), cv2.IMREAD_COLOR
        )
        if image is None or image.ndim != 3:
            raise InsightFaceInferenceError("canonical asset could not be decoded")

        height, width = image.shape[:2]
        native_faces = self._analyzer.get(image, max_num=self.settings.max_faces + 1)
        if len(native_faces) > self.settings.max_faces:
            raise InsightFaceInferenceError(
                "detected face count exceeds configured bound"
            )

        faces = [self._convert_face(face, width, height) for face in native_faces]
        return tuple(sorted(faces, key=lambda face: (face.bounds.y, face.bounds.x)))

    def _verify_model_files(self) -> None:
        for filename, expected_checksum in BUFFALO_L_MODEL_CHECKSUMS.items():
            model_path = self.settings.model_directory / filename
            if not model_path.is_file():
                raise InsightFaceConfigurationError(
                    f"required pinned model file is missing: {filename}"
                )
            actual_checksum = _file_sha256(model_path)
            if not _constant_time_equal(expected_checksum, actual_checksum):
                raise InsightFaceConfigurationError(
                    f"pinned model checksum mismatch: {filename}"
                )

    def _build_analyzer(self) -> _FaceAnalyzer:
        from insightface.app import FaceAnalysis  # type: ignore[import-untyped]

        analyzer = FaceAnalysis(
            name=self.settings.model_name,
            root=str(self.settings.insightface_root),
            allowed_modules=["detection", "recognition"],
            providers=list(self.settings.execution_providers),
        )
        analyzer.prepare(
            ctx_id=0,
            det_thresh=self.settings.detection_threshold,
            det_size=self.settings.detection_size,
        )
        return cast(_FaceAnalyzer, analyzer)

    def _convert_face(self, face: Any, width: int, height: int) -> DetectedFace:
        bbox = _finite_array(face.bbox, "bounding box", expected_shape=(4,))
        landmarks = _finite_array(face.kps, "landmarks", expected_shape=(5, 2))
        embedding = _finite_array(face.normed_embedding, "embedding")
        if embedding.ndim != 1 or embedding.size != 512:
            raise InsightFaceInferenceError(
                "provider returned an invalid embedding shape"
            )

        x1 = _clamp(float(bbox[0]), 0.0, float(width))
        y1 = _clamp(float(bbox[1]), 0.0, float(height))
        x2 = _clamp(float(bbox[2]), 0.0, float(width))
        y2 = _clamp(float(bbox[3]), 0.0, float(height))
        if x2 <= x1 or y2 <= y1:
            raise InsightFaceInferenceError("provider returned empty face bounds")

        confidence = float(face.det_score)
        if not math.isfinite(confidence) or not 0 <= confidence <= 1:
            raise InsightFaceInferenceError("provider returned invalid confidence")

        return DetectedFace(
            bounds=Bounds(x=x1, y=y1, width=x2 - x1, height=y2 - y1),
            landmarks=[
                LandmarkPoint(
                    x=_clamp(float(point[0]), 0.0, float(width)),
                    y=_clamp(float(point[1]), 0.0, float(height)),
                )
                for point in landmarks
            ],
            landmark_scheme="5-point",
            detection_confidence=confidence,
            embedding=[float(value) for value in embedding],
            embedding_dimension=512,
            embedding_dtype="float32",
        )


def _file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as model_file:
        while chunk := model_file.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def _constant_time_equal(expected: str, actual: str) -> bool:
    import hmac

    return hmac.compare_digest(expected, actual)


def _finite_array(
    value: object, label: str, expected_shape: tuple[int, ...] | None = None
) -> np.ndarray[Any, Any]:
    if value is None:
        raise InsightFaceInferenceError(f"provider returned no {label}")
    array = np.asarray(value, dtype=np.float32)
    if expected_shape is not None and array.shape != expected_shape:
        raise InsightFaceInferenceError(f"provider returned invalid {label} shape")
    if not bool(np.isfinite(array).all()):
        raise InsightFaceInferenceError(f"provider returned non-finite {label}")
    return array


def _clamp(value: float, lower: float, upper: float) -> float:
    return max(lower, min(value, upper))
