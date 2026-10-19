variable "app_name" {
  description = "Application name, used as resource name prefix."
  type        = string
}

variable "environment" {
  description = "Deployment environment (e.g. dev, prod)."
  type        = string
}

variable "region" {
  description = "AWS region (used for CloudWatch logs)."
  type        = string
}

variable "vpc_id" {
  description = "VPC ID for the ALB target group."
  type        = string
}

variable "public_subnet_ids" {
  description = "Public subnet IDs for the ALB."
  type        = list(string)
}

variable "private_subnet_ids" {
  description = "Private subnet IDs for the Fargate tasks."
  type        = list(string)
}

variable "alb_sg_id" {
  description = "Security group ID for the ALB."
  type        = string
}

variable "certificate_arn" {
  description = "ACM certificate ARN for the HTTPS ALB listener."
  type        = string
}

variable "fargate_sg_id" {
  description = "Security group ID for the Fargate tasks."
  type        = string
}

variable "app_image" {
  description = "ECR image URI for the WordPress PHP-FPM container (Dockerfile target: app)."
  type        = string
}

variable "nginx_image" {
  description = "ECR image URI for the nginx container (Dockerfile target: nginx)."
  type        = string
}

variable "media_bucket" {
  description = "S3 bucket for WordPress media (offloaded uploads)."
  type        = string
}

variable "media_cdn_domain" {
  description = "CloudFront domain that serves media files."
  type        = string
}

variable "db_host" {
  description = "RDS endpoint hostname."
  type        = string
}

variable "db_name" {
  description = "WordPress database name."
  type        = string
}

variable "db_user" {
  description = "WordPress database user."
  type        = string
}

variable "db_password_secret_arn" {
  description = "Secrets Manager secret ARN (JSON with a password key) for the DB password."
  type        = string
}

variable "site_url" {
  description = "Canonical HTTPS URL used by WordPress."
  type        = string
}

variable "assign_public_ip" {
  description = "Give Fargate tasks a public IP (needed to reach ECR/logs/Secrets Manager when there are no VPC endpoints or NAT)."
  type        = bool
  default     = false
}

variable "container_port" {
  description = "Port nginx listens on (the ALB target port)."
  type        = number
  default     = 80
}

variable "cpu" {
  description = "Fargate task CPU units."
  type        = number
  default     = 256
}

variable "memory" {
  description = "Fargate task memory (MiB)."
  type        = number
  default     = 512
}

variable "fargate_spot" {
  description = "Run tasks on Fargate Spot instead of on-demand Fargate."
  type        = bool
  default     = false
}

variable "desired_count" {
  description = "Number of running tasks."
  type        = number
  default     = 1
}

variable "health_check_path" {
  description = "ALB target group health check path."
  type        = string
  default     = "/"
}

variable "activate_plugins" {
  description = "Space-separated list of plugins to activate on container startup."
  type        = string
  default     = "woocommerce amazon-s3-and-cloudfront"
}

variable "activate_theme" {
  description = "Theme slug to activate on container startup (optional)."
  type        = string
  default     = ""
}

variable "admin_email" {
  description = "WordPress admin email address for initial site installation."
  type        = string
  default     = "admin@example.com"
}

variable "site_title" {
  description = "WordPress site title for initial site installation."
  type        = string
  default     = "AutoSourceChina"
}
