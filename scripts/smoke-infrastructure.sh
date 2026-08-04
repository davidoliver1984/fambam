#!/bin/sh

set -eu

bucket="${AWS_BUCKET:-fambam-media}"
queue="image-analysis-requested"
object="smoke/infrastructure.txt"
payload="fambam-infrastructure-smoke-$(date +%s)-$$"

docker compose exec -T postgres pg_isready -U fambam -d fambam
docker compose exec -T redis redis-cli ping

for required_queue in fambam-jobs image-analysis-requested image-analysis-completed image-analysis-failed; do
    docker compose exec -T localstack awslocal sqs get-queue-url \
        --queue-name "${required_queue}" >/dev/null
done

running_services="$(docker compose ps --status running --services)"
for required_service in api queue-worker scheduler; do
    if ! printf '%s\n' "${running_services}" | grep -qx "${required_service}"; then
        echo "Required service is not running: ${required_service}" >&2
        exit 1
    fi
done

printf '%s' "${payload}" |
    docker compose exec -T localstack awslocal s3 cp - "s3://${bucket}/${object}"

downloaded="$(
    docker compose exec -T localstack \
        awslocal s3 cp "s3://${bucket}/${object}" -
)"

if [ "${downloaded}" != "${payload}" ]; then
    echo "S3 smoke test returned unexpected content" >&2
    exit 1
fi

docker compose exec -T localstack awslocal sqs send-message \
    --queue-url "http://localhost:4566/000000000000/${queue}" \
    --message-body "${payload}" >/dev/null

received_current_message=false
attempt=0

while [ "${attempt}" -lt 10 ]; do
    attempt=$((attempt + 1))
    received="$(
        docker compose exec -T localstack awslocal sqs receive-message \
            --queue-url "http://localhost:4566/000000000000/${queue}" \
            --wait-time-seconds 2 \
            --message-attribute-names All \
            --output json
    )"
    received_body="$(printf '%s' "${received}" | jq -r '.Messages[0].Body // empty')"
    receipt_handle="$(printf '%s' "${received}" | jq -r '.Messages[0].ReceiptHandle // empty')"

    if [ -z "${receipt_handle}" ]; then
        continue
    fi

    docker compose exec -T localstack awslocal sqs delete-message \
        --queue-url "http://localhost:4566/000000000000/${queue}" \
        --receipt-handle "${receipt_handle}"

    if [ "${received_body}" = "${payload}" ]; then
        received_current_message=true
        break
    fi
done

if [ "${received_current_message}" != "true" ]; then
    echo "SQS smoke test returned unexpected content" >&2
    exit 1
fi

docker compose run --rm --no-deps api-migrate php artisan migrate:status >/dev/null

docker compose exec -T api php artisan tinker --execute='cache()->put("infrastructure-smoke", "ok", 60); throw_unless(cache()->get("infrastructure-smoke") === "ok", RuntimeException::class, "Redis cache smoke failed"); $disk = Illuminate\Support\Facades\Storage::disk("s3"); $disk->put("smoke/api.txt", "ok"); throw_unless($disk->get("smoke/api.txt") === "ok", RuntimeException::class, "S3 application smoke failed"); $disk->delete("smoke/api.txt"); $context = App\Tenancy\TenantOperationContext::forBackground("01AAAAAAAAAAAAAAAAAAAAAAAA", (int) (App\Models\User::query()->min("id") ?? 0)); App\Jobs\DeleteFamilySpace::dispatch($context->toArray());'

echo "Infrastructure smoke checks passed."
