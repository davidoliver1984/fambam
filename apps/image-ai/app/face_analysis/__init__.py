"""Provider-neutral contracts and the pinned local provider."""

from app.face_analysis.insightface_provider import (
    InsightFaceProvider,
    InsightFaceSettings,
)

__all__ = ["InsightFaceProvider", "InsightFaceSettings"]

from app.face_analysis.contracts import (
    AnalysisIdentity,
    Bounds,
    DetectedFace,
    FaceAnalysisResult,
    ImageAnalysisCompleted,
    ImageAnalysisFailed,
    ImageAnalysisRequested,
    LandmarkPoint,
    SignedObjectAuthority,
)
from app.face_analysis.provider import FaceAnalysisProvider

__all__ = [
    "AnalysisIdentity",
    "Bounds",
    "DetectedFace",
    "FaceAnalysisProvider",
    "FaceAnalysisResult",
    "ImageAnalysisCompleted",
    "ImageAnalysisFailed",
    "ImageAnalysisRequested",
    "LandmarkPoint",
    "SignedObjectAuthority",
]
