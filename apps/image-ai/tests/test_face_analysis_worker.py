import hashlib
import json
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import pytest

from app.face_analysis import observability as observability_module
from app.face_analysis.contracts import AnalysisIdentity, DetectedFace
from app.face_analysis.insightface_provider import InsightFaceInferenceError
from app.face_analysis.worker import FaceAnalysisWorker


class FakeSqs:
    def __init__(self, body: str) -> None:
        self.body = body
        self.sent: list[dict[str, Any]] = []
        self.deleted: list[dict[str, Any]] = []

    def receive_message(self, **kwargs: Any) -> dict[str, Any]:
        assert kwargs["AttributeNames"] == [
            "SentTimestamp",
            "ApproximateReceiveCount",
        ]
        return {
            "Messages": [
                {
                    "Body": self.body,
                    "ReceiptHandle": "receipt",
                    "Attributes": {
                        "SentTimestamp": str(int((time.time() - 0.1) * 1000)),
                        "ApproximateReceiveCount": "2",
                    },
                }
            ]
        }

    def send_message(self, **kwargs: Any) -> None:
        self.sent.append(kwargs)

    def delete_message(self, **kwargs: Any) -> None:
        self.deleted.append(kwargs)


class FakeProvider:
    def __init__(self) -> None:
        self.called = False

    @property
    def identity(self) -> AnalysisIdentity:
        return AnalysisIdentity(
            provider="insightface-onnxruntime",
            model_identifier="buffalo_l-v0.7",
            model_weight_checksum="b" * 64,
            config_hash="c" * 64,
        )

    def analyze(self, canonical_path: Path) -> tuple[DetectedFace, ...]:
        self.called = True
        assert canonical_path.is_file()
        return ()


class FailingProvider(FakeProvider):
    def analyze(self, canonical_path: Path) -> tuple[DetectedFace, ...]:
        raise InsightFaceInferenceError("provider failed")


class FakeHttp:
    def __init__(self, checksum: str | None = None) -> None:
        self.checksum = checksum
        self.uploaded: bytes | None = None

    def download(
        self, url: str, headers: dict[str, str], target: Path, max_bytes: int
    ) -> str:
        assert "canonical" in url
        success, encoded = cv2.imencode(".png", np.zeros((8, 12, 3), dtype=np.uint8))
        assert success
        target.write_bytes(encoded.tobytes())
        return self.checksum or hashlib.sha256(b"canonical").hexdigest()

    def put_write_once(self, url: str, headers: dict[str, str], body: bytes) -> None:
        assert headers == {"If-None-Match": "*"}
        self.uploaded = body


def test_worker_publishes_bounded_reference_then_deletes_request(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    observed: dict[str, Any] = {}

    @contextmanager
    def observe(*args: Any, **kwargs: Any) -> Any:
        observed["request"] = {"args": args, "kwargs": kwargs}
        yield object()

    monkeypatch.setattr(observability_module, "observe_request", observe)
    monkeypatch.setattr(
        observability_module,
        "record_inference",
        lambda *args, **kwargs: observed.update(inference=kwargs),
    )
    monkeypatch.setattr(
        observability_module,
        "record_success",
        lambda *args, **kwargs: observed.update(success=kwargs),
    )
    provider = FakeProvider()
    http = FakeHttp()
    sqs = FakeSqs(_request())
    worker = _worker(sqs, provider, http)

    assert worker.process_one(wait_time_seconds=0)

    assert provider.called
    assert http.uploaded == b'{"contract_version":"1","faces":[]}'
    assert sqs.deleted
    assert sqs.sent[0]["QueueUrl"] == "completed"
    completed = json.loads(sqs.sent[0]["MessageBody"])
    assert completed["detected_face_count"] == 0
    assert "faces" not in completed
    assert "embedding" not in sqs.sent[0]["MessageBody"]
    assert observed["request"]["kwargs"]["attempt_count"] == 2
    assert observed["request"]["kwargs"]["queue_latency_ms"] >= 0
    assert observed["inference"]["width"] == 12
    assert observed["inference"]["height"] == 8
    assert observed["success"]["detected_face_count"] == 0
    assert observed["success"]["duration_ms"] >= 0
    assert set(observed["success"]) == {
        "detected_face_count",
        "duration_ms",
        "memory_bytes",
    }


def test_checksum_mismatch_fails_without_inference(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    failure: dict[str, Any] = {}
    monkeypatch.setattr(
        observability_module,
        "record_failure",
        lambda *args, **kwargs: failure.update(kwargs),
    )
    provider = FakeProvider()
    sqs = FakeSqs(_request())
    worker = _worker(sqs, provider, FakeHttp("0" * 64))

    worker.process_one(wait_time_seconds=0)

    assert not provider.called
    assert sqs.deleted
    failed = json.loads(sqs.sent[0]["MessageBody"])
    assert failed["failure_category"] == "checksum_mismatch"
    assert failure["category"] == "checksum_mismatch"
    assert failure["duration_ms"] >= 0
    assert set(failure) == {"category", "duration_ms", "memory_bytes"}


def test_invalid_contract_is_left_for_redrive_and_dlq() -> None:
    sqs = FakeSqs('{"request_id":"not-a-ulid"}')
    worker = _worker(sqs, FakeProvider(), FakeHttp())

    worker.process_one(wait_time_seconds=0)

    assert not sqs.sent
    assert not sqs.deleted


def test_failed_inference_still_records_duration_and_bounded_failure(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    observed: dict[str, Any] = {}
    monkeypatch.setattr(
        observability_module,
        "record_inference",
        lambda *args, **kwargs: observed.update(inference=kwargs),
    )
    monkeypatch.setattr(
        observability_module,
        "record_failure",
        lambda *args, **kwargs: observed.update(failure=kwargs),
    )
    sqs = FakeSqs(_request())

    _worker(sqs, FailingProvider(), FakeHttp()).process_one(wait_time_seconds=0)

    assert observed["inference"]["duration_ms"] >= 0
    assert observed["failure"]["category"] == "inference_error"
    assert "provider failed" not in str(observed)


def _worker(sqs: FakeSqs, provider: FakeProvider, http: FakeHttp) -> FaceAnalysisWorker:
    return FaceAnalysisWorker(
        sqs,
        provider,
        http,
        requested_queue_url="requested",
        completed_queue_url="completed",
        failed_queue_url="failed",
    )


def _request() -> str:
    return json.dumps(
        {
            "contract_version": "1",
            "request_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
            "family_space_id": "01ARZ3NDEKTSV4RRFFQ69G5FAW",
            "media_upload_id": "01ARZ3NDEKTSV4RRFFQ69G5FAX",
            "canonical_sha256": hashlib.sha256(b"canonical").hexdigest(),
            "canonical_get_authority": {
                "url": "http://storage/canonical",
                "headers": {},
                "expires_at": "2026-08-27T12:00:00+00:00",
            },
            "result_put_authority": {
                "url": "http://storage/fambam-media/families/f/face-analysis/a/result.json",
                "headers": {"If-None-Match": "*"},
                "expires_at": "2026-08-27T12:00:00+00:00",
            },
            "analysis_identity": {
                "provider": "insightface-onnxruntime",
                "model_identifier": "buffalo_l-v0.7",
                "model_weight_checksum": "b" * 64,
                "config_hash": "c" * 64,
            },
            "correlation_id": "correlation",
            "traceparent": "00-" + "1" * 32 + "-" + "2" * 16 + "-01",
        }
    )
