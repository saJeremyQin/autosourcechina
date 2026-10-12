
# ---------- VPC endpoints (private subnets have no NAT / internet egress) ----------

data "aws_region" "current" {}

locals {
  # Interface endpoints needed for Fargate to pull images and ship logs.
  interface_endpoints = {
    ecr_api = "ecr.api"
    ecr_dkr = "ecr.dkr"
    logs    = "logs"
    # Fargate reads the DB password from Secrets Manager at task start.
    secretsmanager = "secretsmanager"
  }
}

resource "aws_security_group" "vpc_endpoints" {
  name_prefix = "${local.name_prefix}-vpce-"
  description = "Interface VPC endpoints: HTTPS from Fargate tasks"
  vpc_id      = aws_vpc.this.id

  tags = { Name = "${local.name_prefix}-vpce-sg" }

  lifecycle { create_before_destroy = true }
}

resource "aws_vpc_security_group_ingress_rule" "vpc_endpoints_https" {
  security_group_id            = aws_security_group.vpc_endpoints.id
  description                  = "HTTPS from Fargate tasks"
  ip_protocol                  = "tcp"
  from_port                    = 443
  to_port                      = 443
  referenced_security_group_id = aws_security_group.fargate.id
}

# S3 gateway endpoint: free; ECR image layers and app media files are served from S3.
resource "aws_vpc_endpoint" "s3" {
  vpc_id            = aws_vpc.this.id
  service_name      = "com.amazonaws.${data.aws_region.current.name}.s3"
  vpc_endpoint_type = "Gateway"
  route_table_ids   = [aws_route_table.private.id]

  tags = { Name = "${local.name_prefix}-s3-vpce" }
}

resource "aws_vpc_endpoint" "interface" {
  for_each = var.enable_interface_endpoints ? local.interface_endpoints : {}

  vpc_id              = aws_vpc.this.id
  service_name        = "com.amazonaws.${data.aws_region.current.name}.${each.value}"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = [for k in sort(keys(aws_subnet.private)) : aws_subnet.private[k].id]
  security_group_ids  = [aws_security_group.vpc_endpoints.id]
  private_dns_enabled = true

  tags = { Name = "${local.name_prefix}-${each.key}-vpce" }
}
