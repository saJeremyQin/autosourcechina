terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "aws" {
  region = var.region
}

module "networking" {
  source = "./modules/networking"

  app_name       = var.app_name
  environment    = var.environment
  vpc_cidr       = var.vpc_cidr
  container_port = var.ecs_container_port

  enable_interface_endpoints = !var.low_cost_networking

  public_subnets  = var.public_subnets
  private_subnets = var.private_subnets
}

module "rds" {
  source = "./modules/rds"

  app_name                  = var.app_name
  environment               = var.environment
  vpc_id                    = module.networking.vpc_id
  subnet_ids                = module.networking.private_subnet_ids
  allowed_security_group_id = module.networking.fargate_security_group_id
}

module "media" {
  source = "./modules/media"

  app_name     = var.app_name
  environment  = var.environment
  media_bucket = var.media_bucket
}

module "ecs" {
  source = "./modules/ecs"

  app_name    = var.app_name
  environment = var.environment
  region      = var.region

  vpc_id             = module.networking.vpc_id
  public_subnet_ids  = module.networking.public_subnet_ids
  private_subnet_ids = module.networking.private_subnet_ids
  alb_sg_id          = module.networking.alb_security_group_id
  fargate_sg_id      = module.networking.fargate_security_group_id
  assign_public_ip   = var.low_cost_networking

  app_image       = var.app_image
  nginx_image     = var.nginx_image
  container_port  = var.ecs_container_port
  cpu             = var.ecs_cpu
  memory          = var.ecs_memory
  certificate_arn = var.certificate_arn
  site_url        = var.site_url

  media_bucket           = var.media_bucket
  media_cdn_domain       = module.media.cdn_domain
  db_host                = module.rds.db_host
  db_name                = module.rds.db_name
  db_user                = module.rds.db_username
  db_password_secret_arn = module.rds.master_user_secret_arn

  activate_plugins = var.activate_plugins
  activate_theme   = var.activate_theme
  admin_email      = var.admin_email
  site_title       = var.site_title
}

output "alb_dns_name" {
  description = "Public DNS name of the ALB."
  value       = module.ecs.alb_dns_name
}

output "ecs_cluster_name" {
  value = module.ecs.cluster_name
}

output "ecs_service_name" {
  value = module.ecs.service_name
}

output "task_definition_arn" {
  value = module.ecs.task_definition_arn
}