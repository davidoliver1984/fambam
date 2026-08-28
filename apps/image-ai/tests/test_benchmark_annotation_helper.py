import json
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from fastapi.testclient import TestClient

from app.face_analysis.contracts import Bounds, DetectedFace
from scripts.annotate_face_benchmark import (
    AssignmentInput,
    BenchmarkAnnotationStore,
    create_app,
    region_records,
    summarize_annotations,
)


def test_private_assignment_round_trip_is_atomic_mirrored_and_valid(
    tmp_path: Path,
) -> None:
    store = _store(tmp_path)
    saved = store.save_assignments(
        "B001",
        [
            AssignmentInput(
                face_index=0,
                status="labelled",
                identity_id="person-a",
                age_band="child",
                note_flags=["old-scan"],
                private_note="Same anonymous person at a younger age.",
            ),
            AssignmentInput(face_index=1, status="unknown"),
        ],
    )

    assert saved["assignments"][0]["identity_id"] == "person-a"
    assert store.identities() == ["person-a"]
    assert store.validate() == []
    assert (store.root / "annotations.json.pre-identity-annotation.bak").is_file()
    assert (store.root / "manifest.json.pre-identity-annotation.bak").is_file()
    annotations = json.loads(store.annotations_path.read_text(encoding="utf-8"))
    manifest = json.loads(store.manifest_path.read_text(encoding="utf-8"))
    assert (
        annotations["B001"]["anonymous_identity_groups"]
        == manifest["images"][0]["anonymous_identity_groups"]
    )

    corrected = store.save_assignments(
        "B001",
        [
            AssignmentInput(
                face_index=0,
                status="labelled",
                identity_id="person-b",
                age_band="adult-40s-50s",
                note_flags=["glasses"],
                private_note="Corrected after reviewing another appearance.",
            ),
            AssignmentInput(face_index=1, status="unknown"),
        ],
    )
    assert len(corrected["assignments"]) == 2
    assert corrected["assignments"][0]["identity_id"] == "person-b"
    assert store.identities() == ["person-b"]
    assert store.validate() == []


def test_local_api_requires_session_token_for_writes(tmp_path: Path) -> None:
    store = _store(tmp_path)
    client = TestClient(create_app(store, "private-token"))
    payload: dict[str, Any] = {
        "assignments": [
            {
                "face_index": index,
                "status": "skipped",
                "identity_id": None,
                "age_band": "unknown",
                "note_flags": [],
                "private_note": "",
            }
            for index in range(2)
        ]
    }

    assert client.put("/api/images/B001", json=payload).status_code == 403
    response = client.put(
        "/api/images/B001",
        json=payload,
        headers={"X-Local-Session": "private-token"},
    )
    assert response.status_code == 200
    assert response.json()["assignments"][0]["status"] == "skipped"


def test_import_assets_adds_unique_private_auxiliaries(tmp_path: Path) -> None:
    store = _store(tmp_path)
    incoming = tmp_path / "incoming"
    incoming.mkdir()
    encoded, content = cv2.imencode(
        ".jpg", np.full((20, 20, 3), fill_value=127, dtype=np.uint8)
    )
    assert encoded
    (incoming / "new-one.jpg").write_bytes(content.tobytes())
    (incoming / "same-bytes.jpg").write_bytes(content.tobytes())

    result = store.import_assets(incoming)

    assert result["imported"] == ["B002"]
    assert result["exact_duplicates_skipped"] == ["same-bytes.jpg"]
    annotations = store.annotations()
    assert annotations["B002"]["core"] is False
    assert annotations["B002"]["expected_face_count_source"] == (
        "local-detector-provisional"
    )
    manifest = store.manifest()
    assert manifest["image_count"] == 2
    assert manifest["core_image_count"] == 1
    assert manifest["auxiliary_image_count"] == 1
    assert (store.assets / "B002.jpg").is_file()
    assert store.validate() == [
        "B002: no stable face regions; run the local prepare command"
    ]


def test_region_projection_discards_embeddings_and_summary_reports_gaps() -> None:
    face = DetectedFace(
        bounds=Bounds(x=10.0, y=20.0, width=30.0, height=40.0),
        landmarks=[],
        landmark_scheme="5-point",
        detection_confidence=0.9,
        embedding=[0.0] * 512,
        embedding_dimension=512,
        embedding_dtype="float32",
    )

    regions = region_records([face])
    assert regions == [
        {
            "face_index": 0,
            "bounds": {"x": 10.0, "y": 20.0, "width": 30.0, "height": 40.0},
        }
    ]
    assert "embedding" not in json.dumps(regions).lower()

    summary = summarize_annotations(
        {
            "B001": {
                "anonymous_identity_groups": [
                    {
                        "face_index": 0,
                        "status": "labelled",
                        "identity_id": "person-a",
                        "age_band": "child",
                        "note_flags": ["old-scan", "occlusion"],
                        "private_note": "",
                    }
                ]
            },
            "B002": {
                "anonymous_identity_groups": [
                    {
                        "face_index": 0,
                        "status": "labelled",
                        "identity_id": "person-a",
                        "age_band": "adult-40s-50s",
                        "note_flags": ["hairstyle-change"],
                        "private_note": "",
                    }
                ]
            },
        }
    )
    assert summary["identities_with_multiple_appearances"] == ["person-a"]
    assert summary["identities_with_cross_age_coverage"] == ["person-a"]
    assert summary["coverage"]["child_teen_adult_progression"] is True
    assert summary["coverage"]["old_or_damaged_scans"] is True
    assert summary["coverage"]["appearance_changes"] is True
    assert "siblings" in summary["coverage_gaps"]


def _store(root: Path) -> BenchmarkAnnotationStore:
    assets = root / "assets"
    assets.mkdir()
    (assets / "B001.jpg").write_bytes(b"private-test-image")
    assignments: list[dict[str, Any]] = [
        {
            "face_index": index,
            "status": "unlabelled",
            "identity_id": None,
            "age_band": "unknown",
            "note_flags": [],
            "private_note": "",
        }
        for index in range(2)
    ]
    annotation: dict[str, Any] = {
        "expected_face_count": 2,
        "core": True,
        "categories": ["clear-frontal"],
        "difficulty_notes": "test fixture",
        "image_width": 100,
        "image_height": 100,
        "face_regions": [
            {
                "face_index": index,
                "bounds": {"x": 10 * index, "y": 5, "width": 20, "height": 20},
            }
            for index in range(2)
        ],
        "anonymous_identity_groups": assignments,
    }
    (root / "annotations.json").write_text(
        json.dumps({"B001": annotation}), encoding="utf-8"
    )
    (root / "manifest.json").write_text(
        json.dumps(
            {
                "schema_version": 1,
                "images": [
                    {
                        "benchmark_id": "B001",
                        "anonymous_identity_groups": assignments,
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    return BenchmarkAnnotationStore(root)
