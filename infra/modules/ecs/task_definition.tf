resource "aws_ecs_task_definition" "this" {
  family                   = local.name_prefix
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.cpu
  memory                   = var.memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  # nginx and php-fpm share localhost (awsvpc). Static files are baked into the
  # nginx image and media lives in S3, but WordPress still writes some files to
  # uploads/ at runtime (Elementor's generated CSS, files the offloader skips).
  # This task-scoped volume lets nginx serve them; it is emptied whenever the
  # task is replaced, and wp-bootstrap.sh rebuilds Elementor's CSS before nginx
  # starts.
  volume {
    name = "uploads"
  }

  container_definitions = jsonencode([
    {
      name      = "nginx"
      image     = var.nginx_image
      essential = true

      portMappings = [{
        containerPort = var.container_port
        protocol      = "tcp"
      }]

      # Start (and so receive ALB traffic) only after wp-bootstrap.sh has
      # finished: requests that race its Elementor CSS rebuild break the layout.
      dependsOn = [{
        containerName = "wordpress"
        condition     = "HEALTHY"
      }]

      mountPoints = [{
        sourceVolume  = "uploads"
        containerPath = "/var/www/html/wp-content/uploads"
        readOnly      = true
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

      mountPoints = [{
        sourceVolume  = "uploads"
        containerPath = "/var/www/html/wp-content/uploads"
        readOnly      = false
      }]

      # Healthy once wp-bootstrap.sh has finished provisioning. startPeriod covers
      # the database wait and plugin activation on a 0.25 vCPU task; a bootstrap
      # that never finishes fails the task and the circuit breaker rolls back.
      healthCheck = {
        command     = ["CMD-SHELL", "test -f /tmp/wp-bootstrap-ready"]
        interval    = 10
        timeout     = 5
        retries     = 3
        startPeriod = 300
      }

      environment = [
        { name = "WORDPRESS_DB_HOST", value = var.db_host },
        { name = "WORDPRESS_DB_USER", value = var.db_user },
        { name = "WORDPRESS_DB_NAME", value = var.db_name },
        { name = "WORDPRESS_CONFIG_EXTRA", value = local.wordpress_config_extra },
        { name = "WP_SITE_URL", value = var.site_url },
        { name = "WP_ADMIN_USER", value = "admin" },
        { name = "WP_ADMIN_EMAIL", value = var.admin_email },
        { name = "WP_SITE_TITLE", value = var.site_title },
        { name = "WP_ACTIVATE_PLUGINS", value = var.activate_plugins },
        { name = "WP_ACTIVATE_THEME", value = var.activate_theme },
      ]

      secrets = concat(
        [
          { name = "WORDPRESS_DB_PASSWORD", valueFrom = "${var.db_password_secret_arn}:password::" },
          { name = "WP_ADMIN_PASSWORD", valueFrom = "${aws_secretsmanager_secret.wp_admin.arn}:password::" },
        ],
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
