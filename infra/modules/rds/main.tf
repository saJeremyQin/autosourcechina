locals {
  name_prefix = "${var.app_name}-${var.environment}"
}

resource "aws_security_group" "db" {
  name_prefix = "${local.name_prefix}-db-"
  description = "RDS MySQL: connections from Fargate tasks only"
  vpc_id      = var.vpc_id

  tags = { Name = "${local.name_prefix}-db-sg" }

  lifecycle { create_before_destroy = true }
}

resource "aws_vpc_security_group_ingress_rule" "mysql_from_fargate" {
  security_group_id            = aws_security_group.db.id
  description                  = "MySQL from Fargate tasks"
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  referenced_security_group_id = var.allowed_security_group_id
}

resource "aws_db_subnet_group" "this" {
  name       = "${local.name_prefix}-db-subnets"
  subnet_ids = var.subnet_ids

  tags = { Name = "${local.name_prefix}-db-subnets" }
}

resource "aws_db_instance" "this" {
  identifier = "${local.name_prefix}-mysql"

  engine                = "mysql"
  engine_version        = "8.0"
  instance_class        = var.instance_class
  storage_type          = "gp3"
  allocated_storage     = var.allocated_storage
  max_allocated_storage = var.max_allocated_storage
  storage_encrypted     = true

  db_name  = var.db_name
  username = var.db_username
  # RDS generates the password and stores it in Secrets Manager.
  manage_master_user_password = true

  db_subnet_group_name   = aws_db_subnet_group.this.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = false
  multi_az               = var.multi_az

  backup_retention_period    = var.backup_retention_period
  copy_tags_to_snapshot      = true
  auto_minor_version_upgrade = true

  deletion_protection       = false
  skip_final_snapshot       = false
  final_snapshot_identifier = "${local.name_prefix}-mysql-final"

  tags = { Name = "${local.name_prefix}-mysql" }
}
