#!/bin/sh

set -eu

bucket="${AWS_BUCKET:-fambam-media}"
endpoint="${LOCALSTACK_ENDPOINT:-http://localhost:4566}"

if ! awslocal --endpoint-url "${endpoint}" s3api head-bucket --bucket "${bucket}" >/dev/null 2>&1; then
    awslocal --endpoint-url "${endpoint}" s3api create-bucket \
        --bucket "${bucket}" \
        --create-bucket-configuration "LocationConstraint=${AWS_DEFAULT_REGION}"
fi

for queue in \
    fambam-jobs \
    image-analysis-requested \
    image-analysis-completed \
    image-analysis-failed
do
    if ! awslocal --endpoint-url "${endpoint}" sqs get-queue-url --queue-name "${queue}" >/dev/null 2>&1; then
        awslocal --endpoint-url "${endpoint}" sqs create-queue --queue-name "${queue}"
    fi
done
