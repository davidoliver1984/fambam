"""Consume one synthetic image-analysis message for trace verification."""

import json
import logging
import os
import sys
from typing import Any, Protocol, cast

import boto3
from opentelemetry import trace
from opentelemetry.trace import SpanKind
from opentelemetry.trace.propagation.tracecontext import TraceContextTextMapPropagator

from app.telemetry import configure_telemetry


class SqsClient(Protocol):
    """Small SQS surface used by the deterministic consumer."""

    def receive_message(self, **kwargs: Any) -> dict[str, Any]: ...

    def delete_message(self, **kwargs: Any) -> Any: ...


def receive_current_message(
    client: SqsClient,
    queue_url: str,
    expected_correlation_id: str,
    max_attempts: int = 10,
) -> dict[str, Any] | None:
    """Return the current synthetic message, safely ignoring empty or stale receives."""
    for _ in range(max_attempts):
        result: dict[str, Any] = client.receive_message(
            QueueUrl=queue_url,
            MaxNumberOfMessages=1,
            WaitTimeSeconds=2,
            MessageAttributeNames=["All"],
        )
        messages: list[dict[str, Any]] = result.get("Messages", [])
        if not messages:
            continue

        candidate = messages[0]
        try:
            body = json.loads(candidate["Body"])
        except (json.JSONDecodeError, TypeError):
            body = {}

        if (
            body.get("type") == "SyntheticUploadRequested"
            and body.get("correlation_id") == expected_correlation_id
        ):
            return candidate

        client.delete_message(
            QueueUrl=queue_url, ReceiptHandle=candidate["ReceiptHandle"]
        )

    return None


def main() -> None:
    """Receive, trace and delete one message from the requested queue."""
    configure_telemetry()
    expected_correlation_id = sys.argv[1]
    client = cast(
        SqsClient,
        boto3.client(
            "sqs",
            endpoint_url=os.environ["SQS_ENDPOINT"],
            region_name=os.getenv("AWS_DEFAULT_REGION", "eu-west-2"),
            aws_access_key_id=os.getenv("AWS_ACCESS_KEY_ID", "test"),
            aws_secret_access_key=os.getenv("AWS_SECRET_ACCESS_KEY", "test"),
        ),
    )
    queue_url = f"{os.environ['SQS_PREFIX']}/{os.environ['SQS_QUEUE']}"
    message = receive_current_message(client, queue_url, expected_correlation_id)

    if message is None:
        raise RuntimeError("Synthetic message was not received")

    carrier = {
        name: value["StringValue"]
        for name, value in message.get("MessageAttributes", {}).items()
        if "StringValue" in value
    }
    context = TraceContextTextMapPropagator().extract(carrier)
    tracer = trace.get_tracer("fambam-image-ai")

    with tracer.start_as_current_span(
        "image-analysis.requested consume", context=context, kind=SpanKind.CONSUMER
    ):
        logging.getLogger("fambam.image_ai").info(
            "synthetic message consumed",
            extra={
                "message_id": message["MessageId"],
                "correlation_id": expected_correlation_id,
            },
        )

    client.delete_message(QueueUrl=queue_url, ReceiptHandle=message["ReceiptHandle"])
    provider = trace.get_tracer_provider()
    if hasattr(provider, "force_flush"):
        provider.force_flush()


if __name__ == "__main__":
    main()
