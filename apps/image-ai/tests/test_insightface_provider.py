from pathlib import Path
from types import SimpleNamespace
from typing import Any

import cv2
import numpy as np
import pytest

from app.face_analysis.insightface_provider import (
    BUFFALO_L_MODEL_CHECKSUMS,
    InsightFaceConfigurationError,
    InsightFaceInferenceError,
    InsightFaceProvider,
    InsightFaceSettings,
)


class FakeAnalyzer:
    def __init__(self, faces: list[SimpleNamespace]) -> None:
        self.faces = faces
        self.max_num: int | None = None

    def get(
        self, image: np.ndarray[Any, Any], max_num: int = 0
    ) -> list[SimpleNamespace]:
        self.max_num = max_num
        return self.faces


def test_converts_and_orders_provider_output(tmp_path: Path) -> None:
    image_path = tmp_path / "canonical.jpg"
    assert cv2.imwrite(str(image_path), np.zeros((100, 200, 3), dtype=np.uint8))
    right_face = _face([100, 20, 210, 90], score=0.8)
    left_face = _face([-4, 10, 60, 80], score=0.9)
    analyzer = FakeAnalyzer([right_face, left_face])
    provider = InsightFaceProvider(
        InsightFaceSettings(insightface_root=tmp_path),
        analyzer=analyzer,
        verify_model_files=False,
    )

    faces = provider.analyze(image_path)

    assert analyzer.max_num == 257
    assert len(faces) == 2
    assert faces[0].bounds.x == 0
    assert faces[1].bounds.x == 100
    assert faces[1].bounds.width == 100
    assert faces[0].embedding_dimension == 512
    assert faces[0].landmark_scheme == "5-point"


def test_config_hash_excludes_execution_backend(tmp_path: Path) -> None:
    cpu = InsightFaceSettings(
        insightface_root=tmp_path, execution_providers=("CPUExecutionProvider",)
    )
    accelerator = InsightFaceSettings(
        insightface_root=tmp_path,
        execution_providers=("CoreMLExecutionProvider", "CPUExecutionProvider"),
    )

    assert cpu.config_hash == accelerator.config_hash


def test_config_hash_changes_with_logical_output_configuration(tmp_path: Path) -> None:
    baseline = InsightFaceSettings(insightface_root=tmp_path)
    changed = InsightFaceSettings(insightface_root=tmp_path, detection_threshold=0.7)

    assert baseline.config_hash != changed.config_hash


def test_missing_pinned_weight_fails_before_model_load(tmp_path: Path) -> None:
    with pytest.raises(InsightFaceConfigurationError, match="missing"):
        InsightFaceProvider(
            InsightFaceSettings(insightface_root=tmp_path),
            analyzer=FakeAnalyzer([]),
        )


def test_modified_pinned_weight_fails_before_model_load(tmp_path: Path) -> None:
    model_directory = tmp_path / "models" / "buffalo_l"
    model_directory.mkdir(parents=True)
    for filename in BUFFALO_L_MODEL_CHECKSUMS:
        (model_directory / filename).write_bytes(b"substituted")

    with pytest.raises(InsightFaceConfigurationError, match="checksum mismatch"):
        InsightFaceProvider(
            InsightFaceSettings(insightface_root=tmp_path),
            analyzer=FakeAnalyzer([]),
        )


def test_invalid_provider_numbers_fail_closed(tmp_path: Path) -> None:
    image_path = tmp_path / "canonical.jpg"
    assert cv2.imwrite(str(image_path), np.zeros((100, 100, 3), dtype=np.uint8))
    face = _face([10, 10, 50, 50])
    face.normed_embedding[0] = np.nan
    provider = InsightFaceProvider(
        InsightFaceSettings(insightface_root=tmp_path),
        analyzer=FakeAnalyzer([face]),
        verify_model_files=False,
    )

    with pytest.raises(InsightFaceInferenceError, match="non-finite embedding"):
        provider.analyze(image_path)


def _face(bbox: list[float], score: float = 0.75) -> SimpleNamespace:
    return SimpleNamespace(
        bbox=np.asarray(bbox, dtype=np.float32),
        kps=np.asarray(
            [[20, 20], [40, 20], [30, 30], [22, 40], [38, 40]],
            dtype=np.float32,
        ),
        det_score=score,
        normed_embedding=np.full(512, 1 / np.sqrt(512), dtype=np.float32),
    )
