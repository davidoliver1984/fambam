"""Synthetic SQS consumer behaviour tests."""

from typing import Any

from scripts.consume_synthetic import receive_current_message


class FakeSqsClient:
    """Return queued receive responses and record deletions."""

    def __init__(self, responses: list[dict[str, Any]]) -> None:
        self.responses = iter(responses)
        self.deleted_receipts: list[str] = []

    def receive_message(self, **kwargs: Any) -> dict[str, Any]:
        return next(self.responses)

    def delete_message(self, **kwargs: Any) -> None:
        self.deleted_receipts.append(str(kwargs["ReceiptHandle"]))


def test_empty_receives_continue_until_current_message_arrives() -> None:
    trace_id = "0123456789abcdef0123456789abcdef"
    span_id = "0123456789abcdef"
    expected = {
        "MessageId": "current",
        "ReceiptHandle": "current-receipt",
        "Body": '{"type":"SyntheticUploadRequested","correlation_id":"current-run"}',
        "MessageAttributes": {
            "traceparent": {
                "DataType": "String",
                "StringValue": f"00-{trace_id}-{span_id}-01",
            }
        },
    }
    client = FakeSqsClient([{}, {"Messages": []}, {"Messages": [expected]}])

    message = receive_current_message(
        client, "queue-url", "current-run", max_attempts=3
    )

    assert message == expected
    assert message["MessageAttributes"]["traceparent"]["StringValue"].startswith("00-")
    assert client.deleted_receipts == []


def test_stale_messages_are_deleted_and_timeout_returns_none() -> None:
    stale = {
        "MessageId": "stale",
        "ReceiptHandle": "stale-receipt",
        "Body": '{"type":"SyntheticUploadRequested","correlation_id":"old-run"}',
    }
    client = FakeSqsClient([{"Messages": [stale]}, {}])

    message = receive_current_message(
        client, "queue-url", "current-run", max_attempts=2
    )

    assert message is None
    assert client.deleted_receipts == ["stale-receipt"]
