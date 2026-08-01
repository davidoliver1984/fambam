#!/bin/sh

set -eu

bucket="${AWS_BUCKET:-fambam-media}"
queue="image-analysis-requested"
object="smoke/infrastructure.txt"
payload="fambam-infrastructure-smoke"

docker compose exec -T postgres pg_isready -U fambam -d fambam
docker compose exec -T redis redis-cli ping

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

received="$(
    docker compose exec -T localstack awslocal sqs receive-message \
        --queue-url "http://localhost:4566/000000000000/${queue}" \
        --wait-time-seconds 2 \
        --query 'Messages[0].Body' \
        --output text
)"

if [ "${received}" != "${payload}" ]; then
    echo "SQS smoke test returned unexpected content" >&2
    exit 1
fi

docker compose exec -T api php artisan migrate:status >/dev/null

docker compose exec -T api php artisan tinker --execute='cache()->put("infrastructure-smoke", "ok", 60); throw_unless(cache()->get("infrastructure-smoke") === "ok", RuntimeException::class, "Redis cache smoke failed"); $disk = Illuminate\Support\Facades\Storage::disk("s3"); $disk->put("smoke/api.txt", "ok"); throw_unless($disk->get("smoke/api.txt") === "ok", RuntimeException::class, "S3 application smoke failed"); $disk->delete("smoke/api.txt"); $client = new Aws\Sqs\SqsClient(["version" => "latest", "region" => config("queue.connections.sqs.region"), "endpoint" => config("queue.connections.sqs.endpoint"), "credentials" => ["key" => config("queue.connections.sqs.key"), "secret" => config("queue.connections.sqs.secret")]]); $queueUrl = config("queue.connections.sqs.prefix")."/".config("queue.connections.sqs.queue"); $client->sendMessage(["QueueUrl" => $queueUrl, "MessageBody" => "api-infrastructure-smoke"]);'

echo "Infrastructure smoke checks passed."
