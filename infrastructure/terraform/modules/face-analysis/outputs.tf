output "queue_urls" {
  value = { for name, queue in aws_sqs_queue.pipeline : name => queue.url }
}

output "dead_letter_queue_urls" {
  value = { for name, queue in aws_sqs_queue.dead_letter : name => queue.url }
}

output "laravel_policy_arn" {
  value = aws_iam_policy.laravel.arn
}

output "worker_policy_arn" {
  value = aws_iam_policy.worker.arn
}
