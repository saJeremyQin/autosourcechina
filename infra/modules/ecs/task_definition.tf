resource "aws_ecs_task_definition" "this" {
  family                   = local.name_prefix
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.cpu
  memory                   = var.memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  # nginx and php-fpm share localhost (awsvpc). Static files are baked into the
  # nginx image, so there is no shared volume; uploads live in S3.
  container_definitions = jsonencode([
    {
      name      = "nginx"
      image     = var.nginx_image
      essential = true

      portMappings = [{
        containerPort = var.container_port
        protocol      = "tcp"
      }]

      dependsOn = [{
        containerName = "wordpress"
        condition     = "START"
      }]

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          awslogs-group         = aws_cloudwatch_log_group.this.name
          awslogs-region        = var.region
          awslogs-stream-prefix = "nginx"
        }
      }
    },
    {
      name      = "wordpress"
      image     = var.app_image
      essential = true

      portMappings = [{
        containerPort = 9000
        protocol      = "tcp"
      }]

      environment = [
        { name = "WORDPRESS_DB_HOST", value = var.db_host },
        { name = "WORDPRESS_DB_USER", value = var.db_user },
        { name = "WORDPRESS_DB_NAME", value = var.db_name },
        { name = "WORDPRESS_CONFIG_EXTRA", value = local.wordpress_config_extra },
      ]

      secrets = concat(
        [{ name = "WORDPRESS_DB_PASSWORD", valueFrom = "${var.db_password_secret_arn}:password::" }],
        [for n in local.wordpress_salt_names : {
          name      = "WORDPRESS_${n}"
          valueFrom = "${aws_secretsmanager_secret.wordpress_salts.arn}:${n}::"
        }]
      )

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          awslogs-group         = aws_cloudwatch_log_group.this.name
          awslogs-region        = var.region
          awslogs-stream-prefix = "wordpress"
        }
      }
    }
  ])
}
