"""Run the production-shaped raw-SQS face-analysis worker."""

import os
from pathlib import Path
from typing import cast

import boto3

from app.telemetry import configure_telemetry


def main() -> None:
    configure_telemetry()
    from app.face_analysis.insightface_provider import (
        InsightFaceProvider,
        InsightFaceSettings,
    )
    from app.face_analysis.worker import (
        FaceAnalysisWorker,
        SqsClient,
        UrllibObjectHttpClient,
        run_forever,
    )

    execution_providers = ("CPUExecutionProvider",)
    sqs = cast(
        SqsClient,
        boto3.client(
            "sqs",
            endpoint_url=os.getenv("SQS_ENDPOINT"),
            region_name=os.getenv("AWS_DEFAULT_REGION", "eu-west-2"),
        ),
    )
    prefix = os.environ["SQS_PREFIX"].rstrip("/")
    requested_queue = os.getenv(
        "FACE_ANALYSIS_REQUESTED_QUEUE", "image-analysis-requested"
    )
    completed_queue = os.getenv(
        "FACE_ANALYSIS_COMPLETED_QUEUE", "image-analysis-completed"
    )
    failed_queue = os.getenv("FACE_ANALYSIS_FAILED_QUEUE", "image-analysis-failed")
    worker = FaceAnalysisWorker(
        sqs,
        InsightFaceProvider(
            InsightFaceSettings(
                insightface_root=Path(os.environ["FACE_ANALYSIS_ROOT"]),
                execution_providers=execution_providers,
            )
        ),
        UrllibObjectHttpClient(),
        requested_queue_url=f"{prefix}/{requested_queue}",
        completed_queue_url=f"{prefix}/{completed_queue}",
        failed_queue_url=f"{prefix}/{failed_queue}",
        inference_timeout_seconds=int(
            os.getenv("FACE_ANALYSIS_INFERENCE_TIMEOUT_SECONDS", "20")
        ),
        max_canonical_bytes=int(
            os.getenv("MEDIA_UPLOAD_MAX_BYTES", str(100 * 1024 * 1024))
        ),
        max_result_bytes=int(
            os.getenv("FACE_ANALYSIS_RESULT_MAX_BYTES", str(4 * 1024 * 1024))
        ),
        execution_backend=",".join(execution_providers),
    )
    run_forever(worker)


if __name__ == "__main__":
    main()
