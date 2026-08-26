#!/bin/sh

set -eu

bucket="${AWS_BUCKET:-fambam-media}"
endpoint="${LOCALSTACK_ENDPOINT:-http://localhost:4566}"
cors_origin="${CORS_ALLOWED_ORIGIN:-http://localhost:3010}"

if ! awslocal --endpoint-url "${endpoint}" s3api head-bucket --bucket "${bucket}" >/dev/null 2>&1; then
    awslocal --endpoint-url "${endpoint}" s3api create-bucket \
        --bucket "${bucket}" \
        --create-bucket-configuration "LocationConstraint=${AWS_DEFAULT_REGION}"
fi

awslocal --endpoint-url "${endpoint}" s3api put-bucket-cors \
    --bucket "${bucket}" \
    --cors-configuration "{\"CORSRules\":[{\"AllowedHeaders\":[\"*\"],\"AllowedMethods\":[\"PUT\",\"GET\",\"HEAD\"],\"AllowedOrigins\":[\"${cors_origin}\"],\"ExposeHeaders\":[\"ETag\",\"Content-Length\",\"Content-Range\",\"Accept-Ranges\"],\"MaxAgeSeconds\":300}]}"

awslocal --endpoint-url "${endpoint}" s3api put-public-access-block \
    --bucket "${bucket}" \
    --public-access-block-configuration \
    'BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true'

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

jobs_queue_url="$(awslocal --endpoint-url "${endpoint}" sqs get-queue-url \
    --queue-name fambam-jobs --query QueueUrl --output text)"
awslocal --endpoint-url "${endpoint}" sqs set-queue-attributes \
    --queue-url "${jobs_queue_url}" \
    --attributes VisibilityTimeout=960
