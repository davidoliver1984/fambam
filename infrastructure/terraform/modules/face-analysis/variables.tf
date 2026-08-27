variable "name_prefix" {
  description = "Deployment-specific queue-name prefix."
  type        = string
}

variable "media_bucket_arn" {
  description = "ARN of the private family-media bucket."
  type        = string
}

variable "media_bucket_id" {
  description = "Name of the private family-media bucket whose lifecycle this module owns."
  type        = string
}

variable "visibility_timeout_seconds" {
  type    = number
  default = 30
}

variable "max_receive_count" {
  type    = number
  default = 5
}
