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
