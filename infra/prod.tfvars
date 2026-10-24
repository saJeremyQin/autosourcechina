environment         = "prod"
low_cost_networking = true
region              = "ap-southeast-2"
app_name            = "autosourcechina"
repo_name           = "autosourcechina"
ecs_container_port  = 80
ecs_cpu             = 256
ecs_memory          = 1024
ecs_fargate_spot    = true
vpc_cidr            = "10.0.0.0/16"
public_subnets = [
  {
    name              = "public-a"
    cidr_block        = "10.0.1.0/24"
    availability_zone = "ap-southeast-2a"
  },
  {
    name              = "public-b"
    cidr_block        = "10.0.2.0/24"
    availability_zone = "ap-southeast-2b"
  }
]

private_subnets = [
  {
    name              = "private-a"
    cidr_block        = "10.0.101.0/24"
    availability_zone = "ap-southeast-2a"
  },
  {
    name              = "private-b"
    cidr_block        = "10.0.102.0/24"
    availability_zone = "ap-southeast-2b"
  }
]
nginx_image     = "621347135884.dkr.ecr.ap-southeast-2.amazonaws.com/autosourcechina-nginx:latest"
app_image       = "621347135884.dkr.ecr.ap-southeast-2.amazonaws.com/autosourcechina:latest"
media_bucket    = "autosourcechina-media"
certificate_arn = "arn:aws:acm:ap-southeast-2:621347135884:certificate/87a5e698-1a45-4385-95f9-a3f0b6632d73"
site_url        = "https://autosourcechina.com"

# WordPress 初始化与自动激活配置（留空或自定义）
activate_plugins = "woocommerce amazon-s3-and-cloudfront blocksy-companion elementor ht-slider-for-elementor wpforms-lite seo-by-rank-math"
activate_theme   = "blocksy"
admin_email      = "mooji.dev@gmail.com"
site_title       = "AutoSourceChina"