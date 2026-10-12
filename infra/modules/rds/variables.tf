variable "app_name" {
  description = "Application name, used as resource name prefix."
  type        = string
}

variable "environment" {
  description = "Deployment environment (e.g. dev, prod)."
  type        = string
}

variable "vpc_id" {
  description = "VPC ID."
  type        = string
}

variable "subnet_ids" {
  description = "Private subnet IDs (in at least two AZs) for the DB subnet group."
  type        = list(string)
}

variable "allowed_security_group_id" {
  description = "Security group allowed to connect to MySQL (the Fargate tasks)."
  type        = string
}

variable "db_name" {
  description = "Name of the initial database."
  type        = string
  default     = "wordpress"
}

variable "db_username" {
  description = "Master username."
  type        = string
  default     = "wordpress"
}

variable "instance_class" {
  description = "RDS instance class."
  type        = string
  default     = "db.t4g.micro"
}

variable "allocated_storage" {
  description = "Initial storage in GiB."
  type        = number
  default     = 20
}

variable "max_allocated_storage" {
  description = "Storage autoscaling ceiling in GiB."
  type        = number
  default     = 100
}

variable "multi_az" {
  description = "Run a standby in a second AZ."
  type        = bool
  default     = false
}

variable "backup_retention_period" {
  description = "Days to keep automated backups."
  type        = number
  default     = 1
}
