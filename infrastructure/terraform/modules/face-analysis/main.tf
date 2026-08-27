locals {
  queue_names = toset(["requested", "completed", "failed"])
}

resource "aws_sqs_queue" "dead_letter" {
  for_each = local.queue_names
  name     = "${var.name_prefix}-image-analysis-${each.key}-dlq"
}

resource "aws_sqs_queue" "pipeline" {
  for_each                   = local.queue_names
  name                       = "${var.name_prefix}-image-analysis-${each.key}"
  visibility_timeout_seconds = var.visibility_timeout_seconds
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dead_letter[each.key].arn
    maxReceiveCount     = var.max_receive_count
  })
}

data "aws_iam_policy_document" "laravel" {
  statement {
    actions   = ["sqs:SendMessage"]
    resources = [aws_sqs_queue.pipeline["requested"].arn]
  }
  statement {
    actions = ["sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:GetQueueAttributes"]
    resources = [
      aws_sqs_queue.pipeline["completed"].arn,
      aws_sqs_queue.pipeline["failed"].arn,
    ]
  }
  statement {
    actions   = ["s3:GetObject", "s3:DeleteObject", "s3:PutObject", "s3:PutObjectTagging"]
    resources = ["${var.media_bucket_arn}/families/*/face-analysis/*"]
  }
  statement {
    actions   = ["s3:GetObject"]
    resources = ["${var.media_bucket_arn}/families/*/media/*/canonical.*"]
  }
}

data "aws_iam_policy_document" "worker" {
  statement {
    actions   = ["sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:GetQueueAttributes"]
    resources = [aws_sqs_queue.pipeline["requested"].arn]
  }
  statement {
    actions = ["sqs:SendMessage"]
    resources = [
      aws_sqs_queue.pipeline["completed"].arn,
      aws_sqs_queue.pipeline["failed"].arn,
    ]
  }
}

resource "aws_iam_policy" "laravel" {
  name   = "${var.name_prefix}-face-analysis-laravel"
  policy = data.aws_iam_policy_document.laravel.json
}

resource "aws_iam_policy" "worker" {
  name   = "${var.name_prefix}-face-analysis-worker"
  policy = data.aws_iam_policy_document.worker.json
}

resource "aws_s3_bucket_lifecycle_configuration" "face_analysis_results" {
  bucket = var.media_bucket_id

  rule {
    id     = "expire-transient-face-analysis-results"
    status = "Enabled"

    filter {
      tag {
        key   = "fambam-retention"
        value = "face-analysis-transient"
      }
    }

    expiration {
      days = 1
    }
  }
}
