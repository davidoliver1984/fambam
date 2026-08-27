"""Raw-SQS face-analysis worker with narrow signed-object authority."""

from __future__ import annotations

import hashlib
import logging
import os
import signal
import tempfile
import urllib.error
import urllib.request
from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Protocol

from app.face_analysis.contracts import (
    FaceAnalysisResult,
    ImageAnalysisCompleted,
    ImageAnalysisFailed,
    ImageAnalysisRequested,
)
from app.face_analysis.insightface_provider import InsightFaceInferenceError
from app.face_analysis.provider import FaceAnalysisProvider

LOGGER = logging.getLogger("fambam.face_analysis.worker")


class SqsClient(Protocol):
    def receive_message(self, **kwargs: Any) -> dict[str, Any]: ...
    def send_message(self, **kwargs: Any) -> Any: ...
    def delete_message(self, **kwargs: Any) -> Any: ...


class ObjectHttpClient(Protocol):
    def download(
        self, url: str, headers: dict[str, str], target: Path, max_bytes: int
    ) -> str: ...
    def put_write_once(
        self, url: str, headers: dict[str, str], body: bytes
    ) -> None: ...


class AnalysisTimeout(RuntimeError):
    """Inference exceeded its calibrated hard timeout."""


class UrllibObjectHttpClient:
    """Bounded HTTP client for the exact objects granted by Laravel."""

    def download(
        self, url: str, headers: dict[str, str], target: Path, max_bytes: int
    ) -> str:
        request = urllib.request.Request(url, headers=headers, method="GET")
        digest = hashlib.sha256()
        total = 0
        with (
            urllib.request.urlopen(request, timeout=30) as response,
            target.open("wb") as output,
        ):
            while chunk := response.read(1024 * 1024):
                total += len(chunk)
                if total > max_bytes:
                    raise OSError("canonical asset exceeds configured byte bound")
                digest.update(chunk)
                output.write(chunk)
        return digest.hexdigest()

    def put_write_once(self, url: str, headers: dict[str, str], body: bytes) -> None:
        request = urllib.request.Request(url, data=body, headers=headers, method="PUT")
        try:
            with urllib.request.urlopen(request, timeout=30):
                pass
        except urllib.error.HTTPError as exception:
            if exception.code != 412:
                raise
            # Redelivery after an accepted write is safe. Laravel verifies that the
            # stored artifact matches the checksum carried by this completion.


class FaceAnalysisWorker:
    def __init__(
        self,
        sqs: SqsClient,
        provider: FaceAnalysisProvider,
        http: ObjectHttpClient,
        *,
        requested_queue_url: str,
        completed_queue_url: str,
        failed_queue_url: str,
        inference_timeout_seconds: int = 20,
        max_canonical_bytes: int = 100 * 1024 * 1024,
        max_result_bytes: int = 4 * 1024 * 1024,
    ) -> None:
        self.sqs = sqs
        self.provider = provider
        self.http = http
        self.requested_queue_url = requested_queue_url
        self.completed_queue_url = completed_queue_url
        self.failed_queue_url = failed_queue_url
        self.inference_timeout_seconds = inference_timeout_seconds
        self.max_canonical_bytes = max_canonical_bytes
        self.max_result_bytes = max_result_bytes

    def process_one(self, wait_time_seconds: int = 10) -> bool:
        response = self.sqs.receive_message(
            QueueUrl=self.requested_queue_url,
            MaxNumberOfMessages=1,
            WaitTimeSeconds=wait_time_seconds,
            VisibilityTimeout=30,
        )
        messages = response.get("Messages", [])
        if not messages:
            return False
        message = messages[0]
        receipt = message.get("ReceiptHandle")
        if not isinstance(receipt, str):
            return False
        try:
            requested = ImageAnalysisRequested.model_validate_json(
                message.get("Body", "")
            )
        except Exception:
            LOGGER.warning(
                "face-analysis request rejected", extra={"category": "invalid_contract"}
            )
            return True

        terminal_sent = self._process(requested)
        if terminal_sent:
            self.sqs.delete_message(
                QueueUrl=self.requested_queue_url, ReceiptHandle=receipt
            )
        return True

    def _process(self, requested: ImageAnalysisRequested) -> bool:
        failure_category: str | None = None
        failure_detail = ""
        with tempfile.TemporaryDirectory(prefix="fambam-face-analysis-") as directory:
            canonical_path = Path(directory) / "canonical"
            try:
                if requested.analysis_identity != self.provider.identity:
                    failure_category = "inference_error"
                    failure_detail = "Requested analysis identity is unavailable."
                    raise InsightFaceInferenceError(failure_detail)
                actual_sha256 = self.http.download(
                    requested.canonical_get_authority.url,
                    requested.canonical_get_authority.headers,
                    canonical_path,
                    self.max_canonical_bytes,
                )
                if not _constant_time_equal(requested.canonical_sha256, actual_sha256):
                    failure_category = "checksum_mismatch"
                    failure_detail = "Canonical checksum verification failed."
                else:
                    with _hard_timeout(self.inference_timeout_seconds):
                        faces = self.provider.analyze(canonical_path)
                    result = FaceAnalysisResult(contract_version="1", faces=list(faces))
                    artifact = result.model_dump_json().encode("utf-8")
                    if len(artifact) > self.max_result_bytes:
                        failure_category = "result_artifact_oversized"
                        failure_detail = "Result artifact exceeds its byte limit."
                    else:
                        result_sha256 = hashlib.sha256(artifact).hexdigest()
                        self.http.put_write_once(
                            requested.result_put_authority.url,
                            requested.result_put_authority.headers,
                            artifact,
                        )
                        completed = ImageAnalysisCompleted(
                            contract_version=requested.contract_version,
                            request_id=requested.request_id,
                            family_space_id=requested.family_space_id,
                            media_upload_id=requested.media_upload_id,
                            canonical_sha256=requested.canonical_sha256,
                            analysis_identity=requested.analysis_identity,
                            result_object_key=_result_key_from_url(
                                requested.result_put_authority.url
                            ),
                            result_sha256=result_sha256,
                            detected_face_count=len(faces),
                        )
                        self.sqs.send_message(
                            QueueUrl=self.completed_queue_url,
                            MessageBody=completed.model_dump_json(),
                        )
                        LOGGER.info(
                            "face-analysis request completed",
                            extra={
                                "request_id": requested.request_id,
                                "detected_face_count": len(faces),
                                "provider": requested.analysis_identity.provider,
                                "model_identifier": (
                                    requested.analysis_identity.model_identifier
                                ),
                            },
                        )
                        return True
            except AnalysisTimeout:
                failure_category = "timeout"
                failure_detail = "Inference exceeded its timeout."
            except InsightFaceInferenceError as exception:
                failure_category = (
                    "decode_error"
                    if "decode" in str(exception).lower()
                    else "inference_error"
                )
                failure_detail = (
                    "Canonical decoding failed."
                    if failure_category == "decode_error"
                    else "Provider inference failed."
                )
            except (OSError, urllib.error.URLError):
                failure_category = "canonical_unavailable"
                failure_detail = "Canonical asset could not be fetched."
            except Exception:
                failure_category = "inference_error"
                failure_detail = "Face-analysis processing failed."

        assert failure_category is not None
        failed = ImageAnalysisFailed(
            contract_version=requested.contract_version,
            request_id=requested.request_id,
            family_space_id=requested.family_space_id,
            media_upload_id=requested.media_upload_id,
            canonical_sha256=requested.canonical_sha256,
            analysis_identity=requested.analysis_identity,
            failure_category=failure_category,  # type: ignore[arg-type]
            failure_detail=failure_detail,
        )
        self.sqs.send_message(
            QueueUrl=self.failed_queue_url,
            MessageBody=failed.model_dump_json(),
        )
        LOGGER.warning(
            "face-analysis request failed",
            extra={"request_id": requested.request_id, "category": failure_category},
        )
        return True


def run_forever(worker: FaceAnalysisWorker) -> None:
    while True:
        worker.process_one()


@contextmanager
def _hard_timeout(seconds: int) -> Iterator[None]:
    if not hasattr(signal, "SIGALRM"):
        yield
        return

    def alarm_handler(_signum: int, _frame: object) -> None:
        raise AnalysisTimeout

    previous = signal.signal(signal.SIGALRM, alarm_handler)
    signal.alarm(seconds)
    try:
        yield
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous)


def _constant_time_equal(expected: str, actual: str) -> bool:
    import hmac

    return hmac.compare_digest(expected, actual)


def _result_key_from_url(url: str) -> str:
    from urllib.parse import unquote, urlsplit

    path = unquote(urlsplit(url).path).lstrip("/")
    bucket = os.getenv("AWS_BUCKET", "fambam-media")
    prefix = f"{bucket}/"
    return path[len(prefix) :] if path.startswith(prefix) else path
