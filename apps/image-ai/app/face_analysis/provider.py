"""Replaceable provider boundary for face detection and embedding."""

from pathlib import Path
from typing import Protocol

from app.face_analysis.contracts import AnalysisIdentity, DetectedFace


class FaceAnalysisProvider(Protocol):
    """Analyze one verified canonical asset without owning application state."""

    @property
    def identity(self) -> AnalysisIdentity:
        """Return the logical provider/model/config identity."""
        ...

    def analyze(self, canonical_path: Path) -> tuple[DetectedFace, ...]:
        """Return immutable provider-neutral detections for one local file."""
        ...
