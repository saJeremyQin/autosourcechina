resource "aws_ecs_service" "this" {
  name            = "${local.name_prefix}-service"
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.this.arn
  desired_count   = var.desired_count

  # Spot is ~70% cheaper; AWS may reclaim the task with 2 minutes' notice and ECS
  # starts a replacement, so expect brief outages.
  capacity_provider_strategy {
    capacity_provider = var.fargate_spot ? "FARGATE_SPOT" : "FARGATE"
    weight            = 1
  }
  force_new_deployment = true

  # With VPC endpoints, tasks run in private subnets. Without them, tasks run in
  # public subnets with a public IP; the security group still admits only the ALB.
  network_configuration {
    subnets          = var.assign_public_ip ? var.public_subnet_ids : var.private_subnet_ids
    security_groups  = [var.fargate_sg_id]
    assign_public_ip = var.assign_public_ip
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.this.arn
    container_name   = "nginx"
    container_port   = var.container_port
  }

  # Enable deployment circuit breaker to automatically roll back failed deployments.
  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  # CI registers new task definition revisions on each deploy; don't let apply revert them.
  lifecycle {
    ignore_changes = [task_definition]
  }

  depends_on = [aws_lb_listener.http, aws_lb_listener.https, aws_lb_listener_rule.site]
}
