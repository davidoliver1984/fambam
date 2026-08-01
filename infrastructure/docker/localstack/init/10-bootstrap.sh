#!/bin/sh

set -eu

bucket="${AWS_BUCKET:-fambam-media}"

if ! awslocal s3api head-bucket --bucket "${bucket}" >/dev/null 2>&1; then
    awslocal s3api create-bucket \
        --bucket "${bucket}" \
        --create-bucket-configuration "LocationConstraint=${AWS_DEFAULT_REGION}"
fi

for queue in \
    image-analysis-requested \
    image-analysis-completed \
    image-analysis-failed
do
    if ! awslocal sqs get-queue-url --queue-name "${queue}" >/dev/null 2>&1; then
        awslocal sqs create-queue --queue-name "${queue}"
    fi
done
