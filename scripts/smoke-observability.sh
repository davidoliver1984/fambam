#!/bin/sh

set -eu

request_id="observability-smoke-request-$$"
correlation_id="observability-smoke-correlation-$$"
response_headers="$(mktemp)"
trap 'rm -f "${response_headers}"' EXIT

producer_response="$(curl --fail --silent --show-error \
    --dump-header "${response_headers}" \
    --request POST \
    --header "X-Request-ID: ${request_id}" \
    --header "X-Correlation-ID: ${correlation_id}" \
    "http://127.0.0.1:${API_PORT:-8082}/api/observability/synthetic-upload")"

grep -qi "^X-Request-ID: ${request_id}" "${response_headers}"
grep -qi "^X-Correlation-ID: ${correlation_id}" "${response_headers}"

producer_trace_id="$(printf '%s' "${producer_response}" | sed -n 's/.*"trace_id":"\([0-9a-f]*\)".*/\1/p')"
consumer_output="$(docker compose exec -T image-ai .venv/bin/python -m scripts.consume_synthetic "${correlation_id}" 2>&1)"
consumer_trace_id="$(printf '%s' "${consumer_output}" | sed -n 's/.*"trace_id":"\([0-9a-f]*\)".*/\1/p')"

if [ -z "${producer_trace_id}" ] || [ "${producer_trace_id}" != "${consumer_trace_id}" ]; then
    echo "Trace context did not propagate across SQS" >&2
    exit 1
fi

sleep 2

docker compose logs otel-collector | grep -q "synthetic-upload.request"
docker compose logs otel-collector | grep -q "image-analysis.requested consume"
curl --fail --silent --show-error --output /dev/null \
    "http://127.0.0.1:${GRAFANA_PORT:-3011}/api/health"

echo "Observability smoke checks passed."
