import hashlib
import json
from pathlib import Path
from typing import Any

from app.face_analysis.contracts import AnalysisIdentity, DetectedFace
from app.face_analysis.worker import FaceAnalysisWorker


class FakeSqs:
    def __init__(self, body: str) -> None:
        self.body = body
        self.sent: list[dict[str, Any]] = []
        self.deleted: list[dict[str, Any]] = []

    def receive_message(self, **kwargs: Any) -> dict[str, Any]:
        return {"Messages": [{"Body": self.body, "ReceiptHandle": "receipt"}]}

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
        assert canonical_path.read_bytes() == b"canonical"
        return ()


class FakeHttp:
    def __init__(self, checksum: str | None = None) -> None:
        self.checksum = checksum
        self.uploaded: bytes | None = None

    def download(
        self, url: str, headers: dict[str, str], target: Path, max_bytes: int
    ) -> str:
        assert "canonical" in url
        target.write_bytes(b"canonical")
        return self.checksum or hashlib.sha256(b"canonical").hexdigest()

    def put_write_once(self, url: str, headers: dict[str, str], body: bytes) -> None:
        assert headers == {"If-None-Match": "*"}
        self.uploaded = body


def test_worker_publishes_bounded_reference_then_deletes_request() -> None:
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


def test_checksum_mismatch_fails_without_inference() -> None:
    provider = FakeProvider()
    sqs = FakeSqs(_request())
    worker = _worker(sqs, provider, FakeHttp("0" * 64))

    worker.process_one(wait_time_seconds=0)

    assert not provider.called
    assert sqs.deleted
    failed = json.loads(sqs.sent[0]["MessageBody"])
    assert failed["failure_category"] == "checksum_mismatch"


def test_invalid_contract_is_left_for_redrive_and_dlq() -> None:
    sqs = FakeSqs('{"request_id":"not-a-ulid"}')
    worker = _worker(sqs, FakeProvider(), FakeHttp())

    worker.process_one(wait_time_seconds=0)

    assert not sqs.sent
    assert not sqs.deleted


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
