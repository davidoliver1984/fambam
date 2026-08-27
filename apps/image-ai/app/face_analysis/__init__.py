"""Provider-neutral face-analysis contracts."""

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
