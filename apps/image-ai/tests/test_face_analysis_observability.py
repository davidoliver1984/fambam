from app.face_analysis.contracts import AnalysisIdentity
from app.face_analysis.observability import identity_attributes


def test_operational_identity_attributes_exclude_family_and_biometric_values() -> None:
    attributes = identity_attributes(
        AnalysisIdentity(
            provider="insightface-onnxruntime",
            model_identifier="buffalo_l-v0.7",
            model_weight_checksum="b" * 64,
            config_hash="c" * 64,
        ),
        "CPUExecutionProvider",
    )

    assert attributes == {
        "face_analysis.provider": "insightface-onnxruntime",
        "face_analysis.model": "buffalo_l-v0.7",
        "face_analysis.config_hash": "c" * 64,
        "face_analysis.execution_backend": "CPUExecutionProvider",
    }
    rendered = str(attributes).lower()
    assert "family_space" not in rendered
    assert "media_upload" not in rendered
    assert "embedding" not in rendered
    assert "landmark" not in rendered
    assert "bounds" not in rendered
    assert "url" not in rendered
