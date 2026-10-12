variable "region" {
  description = "AWS region to deploy into."
  type        = string
}

variable "app_name" {
  description = "Application name, used as resource name prefix."
  type        = string
}

variable "environment" {
  description = "Deployment environment (e.g. dev, prod)."
  type        = string
}

variable "low_cost_networking" {
  description = "Skip paid interface VPC endpoints and run Fargate tasks with public IPs in public subnets."
  type        = bool
  default     = false
}

variable "repo_name" {
  description = "Repository name used by sibling modules."
  type        = string
}

variable "ecs_container_port" {
  description = "Container port exposed by the ECS service and allowed by networking."
  type        = number
  default     = 80
}

variable "vpc_cidr" {
  description = "CIDR block for the VPC."
  type        = string
}

variable "public_subnets" {
  description = "Public subnets to create."
  type = list(object({
    name              = string
    cidr_block        = string
    availability_zone = string
  }))
}

variable "private_subnets" {
  description = "Private subnets to create."
  type = list(object({
    name              = string
    cidr_block        = string
    availability_zone = string
  }))
}
variable "app_image" {
  description = "ECR image URI for the WordPress PHP-FPM container."
  type        = string
}

variable "nginx_image" {
  description = "ECR image URI for the nginx container."
  type        = string
}

variable "media_bucket" {
  description = "S3 bucket name for WordPress media."
  type        = string
}
