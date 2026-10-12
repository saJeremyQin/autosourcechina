variable "app_name" {
  description = "Application name, used as resource name prefix."
  type        = string
}

variable "environment" {
  description = "Deployment environment (e.g. dev, prod)."
  type        = string
}

variable "media_bucket" {
  description = "Existing S3 bucket that holds WordPress media. It stays private; only CloudFront can read it publicly."
  type        = string
}

variable "price_class" {
  description = "CloudFront price class. PriceClass_All includes Australia/New Zealand edges; PriceClass_100 (US/EU) is cheaper but slower for AU visitors."
  type        = string
  default     = "PriceClass_All"
}
