variable "app_name" {
  description = "Application name, used as resource name prefix."
  type        = string
}

variable "environment" {
  description = "Deployment environment (e.g. dev, prod)."
  type        = string
}

variable "vpc_cidr" {
  description = "CIDR block for the VPC."
  type        = string
}

variable "container_port" {
  description = "Container port the ALB forwards to."
  type        = number
  default     = 80
}

variable "enable_interface_endpoints" {
  description = "Create paid interface VPC endpoints (ECR, logs, Secrets Manager) so private-subnet tasks work without internet egress."
  type        = bool
  default     = true
}

variable "public_subnets" {
  description = "Public subnets (at least two, in different AZs, required by the ALB)."
  type = list(object({
    name              = string
    cidr_block        = string
    availability_zone = string
  }))

  validation {
    condition     = length(var.public_subnets) >= 2 && length(distinct([for s in var.public_subnets : s.availability_zone])) >= 2
    error_message = "Provide at least two public subnets in different availability zones."
  }
}

variable "private_subnets" {
  description = "Private subnets for Fargate tasks (at least two, in different AZs)."
  type = list(object({
    name              = string
    cidr_block        = string
    availability_zone = string
  }))

  validation {
    condition     = length(var.private_subnets) >= 2 && length(distinct([for s in var.private_subnets : s.availability_zone])) >= 2
    error_message = "Provide at least two private subnets in different availability zones."
  }
}
