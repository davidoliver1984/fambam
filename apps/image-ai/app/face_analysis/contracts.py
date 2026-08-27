"""Versioned, provider-neutral face-analysis wire and result models."""

from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

ContractVersion = Literal["1"]
Sha256 = Annotated[str, Field(pattern=r"^[a-f0-9]{64}$")]
Ulid = Annotated[str, Field(pattern=r"^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$")]
FiniteFloat = Annotated[float, Field(allow_inf_nan=False)]


class ContractModel(BaseModel):
    """Reject unknown fields so producer/consumer drift fails closed."""

    model_config = ConfigDict(extra="forbid", frozen=True, strict=True)


class AnalysisIdentity(ContractModel):
    provider: Annotated[str, Field(min_length=1, max_length=80)]
    model_identifier: Annotated[str, Field(min_length=1, max_length=160)]
    model_weight_checksum: Sha256
    config_hash: Sha256


class SignedObjectAuthority(ContractModel):
    url: Annotated[str, Field(min_length=1, max_length=4096)]
    headers: Annotated[
        dict[str, Annotated[str, Field(max_length=2048)]], Field(max_length=16)
    ]
    expires_at: Annotated[str, Field(min_length=20, max_length=40)]


class LandmarkPoint(ContractModel):
    x: FiniteFloat
    y: FiniteFloat


class Bounds(ContractModel):
    x: Annotated[FiniteFloat, Field(ge=0)]
    y: Annotated[FiniteFloat, Field(ge=0)]
    width: Annotated[FiniteFloat, Field(gt=0)]
    height: Annotated[FiniteFloat, Field(gt=0)]


class DetectedFace(ContractModel):
    bounds: Bounds
    landmarks: Annotated[list[LandmarkPoint], Field(max_length=128)]
    landmark_scheme: Annotated[str, Field(min_length=1, max_length=40)]
    detection_confidence: Annotated[FiniteFloat, Field(ge=0, le=1)]
    embedding: Annotated[list[FiniteFloat], Field(max_length=4096)]
    embedding_dimension: Annotated[int, Field(ge=1, le=4096)]
    embedding_dtype: Literal["float32"]
    quality_signals: Annotated[
        dict[str, Annotated[float | bool | None, Field(allow_inf_nan=False)]],
        Field(max_length=32),
    ] = Field(default_factory=dict)
    provider_diagnostics: dict[str, object] | None = None

    @model_validator(mode="after")
    def embedding_matches_declared_dimension(self) -> "DetectedFace":
        if len(self.embedding) != self.embedding_dimension:
            raise ValueError("embedding length must equal embedding_dimension")
        return self


class FaceAnalysisResult(ContractModel):
    contract_version: ContractVersion
    faces: Annotated[list[DetectedFace], Field(max_length=256)]


class ImageAnalysisRequested(ContractModel):
    contract_version: ContractVersion
    request_id: Ulid
    family_space_id: Ulid
    media_upload_id: Ulid
    canonical_sha256: Sha256
    canonical_get_authority: SignedObjectAuthority
    result_put_authority: SignedObjectAuthority
    analysis_identity: AnalysisIdentity
    correlation_id: Annotated[str, Field(min_length=1, max_length=128)]
    traceparent: Annotated[
        str,
        Field(pattern=r"^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$"),
    ]


class ImageAnalysisCompleted(ContractModel):
    contract_version: ContractVersion
    request_id: Ulid
    family_space_id: Ulid
    media_upload_id: Ulid
    canonical_sha256: Sha256
    analysis_identity: AnalysisIdentity
    result_object_key: Annotated[str, Field(min_length=1, max_length=512)]
    result_sha256: Sha256
    detected_face_count: Annotated[int, Field(ge=0, le=256)]


class ImageAnalysisFailed(ContractModel):
    contract_version: ContractVersion
    request_id: Ulid
    family_space_id: Ulid
    media_upload_id: Ulid
    canonical_sha256: Sha256
    analysis_identity: AnalysisIdentity
    failure_category: Literal[
        "checksum_mismatch",
        "canonical_unavailable",
        "decode_error",
        "inference_error",
        "timeout",
        "result_checksum_mismatch",
        "result_artifact_invalid",
        "result_artifact_oversized",
        "attempt_timed_out",
    ]
    failure_detail: Annotated[str, Field(max_length=512)]
