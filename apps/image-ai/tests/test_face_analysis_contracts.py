"""Provider-neutral contract regressions."""

from pathlib import Path

import pytest
from pydantic import ValidationError

from app.face_analysis import (
    AnalysisIdentity,
    Bounds,
    DetectedFace,
    FaceAnalysisProvider,
    FaceAnalysisResult,
    ImageAnalysisCompleted,
    ImageAnalysisFailed,
    ImageAnalysisRequested,
    LandmarkPoint,
    SignedObjectAuthority,
)


def identity() -> AnalysisIdentity:
    return AnalysisIdentity(
        provider="test-provider",
        model_identifier="test-model",
        model_weight_checksum="a" * 64,
        config_hash="b" * 64,
    )


def face() -> DetectedFace:
    return DetectedFace(
        bounds=Bounds(x=10.0, y=20.0, width=30.0, height=40.0),
        landmarks=[LandmarkPoint(x=15.0, y=25.0)],
        landmark_scheme="1-point-test",
        detection_confidence=0.95,
        embedding=[0.1, 0.2],
        embedding_dimension=2,
        embedding_dtype="float32",
    )


def test_embedding_dimension_must_match_vector_length() -> None:
    with pytest.raises(ValidationError, match="embedding length"):
        DetectedFace(
            bounds=Bounds(x=0.0, y=0.0, width=1.0, height=1.0),
            landmarks=[],
            landmark_scheme="5-point",
            detection_confidence=1.0,
            embedding=[0.1],
            embedding_dimension=2,
            embedding_dtype="float32",
        )


def test_contract_rejects_unknown_provider_fields() -> None:
    with pytest.raises(ValidationError, match="Extra inputs"):
        AnalysisIdentity.model_validate(
            {
                **identity().model_dump(),
                "provider_native_model": "must not leak",
            }
        )


def test_provider_protocol_is_directly_callable_without_http() -> None:
    class SyntheticProvider:
        @property
        def identity(self) -> AnalysisIdentity:
            return identity()

        def analyze(self, canonical_path: Path) -> tuple[DetectedFace, ...]:
            assert canonical_path == Path("canonical.jpg")
            return (face(),)

    provider: FaceAnalysisProvider = SyntheticProvider()

    assert provider.identity == identity()
    assert provider.analyze(Path("canonical.jpg")) == (face(),)


def test_versioned_request_completion_failure_and_artifact_shapes_are_closed() -> None:
    authority = SignedObjectAuthority(
        url="http://localstack:4566/fambam-media/object?signature=test",
        headers={"If-None-Match": "*"},
        expires_at="2026-08-27T12:00:00Z",
    )
    request = ImageAnalysisRequested(
        contract_version="1",
        request_id="01ARZ3NDEKTSV4RRFFQ69G5FAV",
        family_space_id="01ARZ3NDEKTSV4RRFFQ69G5FAW",
        media_upload_id="01ARZ3NDEKTSV4RRFFQ69G5FAX",
        canonical_sha256="a" * 64,
        analysis_identity=identity(),
        canonical_get_authority=authority,
        result_put_authority=authority,
        correlation_id="correlation-1",
        traceparent=f"00-{'1' * 32}-{'2' * 16}-01",
    )
    completed = ImageAnalysisCompleted(
        contract_version="1",
        request_id="01ARZ3NDEKTSV4RRFFQ69G5FAV",
        family_space_id="01ARZ3NDEKTSV4RRFFQ69G5FAW",
        media_upload_id="01ARZ3NDEKTSV4RRFFQ69G5FAX",
        canonical_sha256="a" * 64,
        analysis_identity=identity(),
        result_object_key="families/family/face-analysis/attempt/result.json",
        result_sha256="b" * 64,
        detected_face_count=1,
    )
    failed = ImageAnalysisFailed(
        contract_version="1",
        request_id="01ARZ3NDEKTSV4RRFFQ69G5FAV",
        family_space_id="01ARZ3NDEKTSV4RRFFQ69G5FAW",
        media_upload_id="01ARZ3NDEKTSV4RRFFQ69G5FAX",
        canonical_sha256="a" * 64,
        analysis_identity=identity(),
        failure_category="inference_error",
        failure_detail="provider failed without biometric details",
    )
    result = FaceAnalysisResult(contract_version="1", faces=[face()])

    assert request.contract_version == "1"
    assert completed.detected_face_count == len(result.faces)
    assert failed.failure_category == "inference_error"
