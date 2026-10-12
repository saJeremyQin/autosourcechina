locals {
  wordpress_salt_names = [
    "AUTH_KEY",
    "SECURE_AUTH_KEY",
    "LOGGED_IN_KEY",
    "NONCE_KEY",
    "AUTH_SALT",
    "SECURE_AUTH_SALT",
    "LOGGED_IN_SALT",
    "NONCE_SALT",
  ]
}

# Generated once and stored in state, so every task (and every deploy) uses the same
# values. Rotating them logs all users out.
resource "random_password" "wordpress_salt" {
  for_each = toset(local.wordpress_salt_names)

  length  = 64
  special = false
}

resource "aws_secretsmanager_secret" "wordpress_salts" {
  name                    = "${local.name_prefix}/wordpress-salts"
  description             = "WordPress auth keys and salts"
  recovery_window_in_days = 7
}

resource "aws_secretsmanager_secret_version" "wordpress_salts" {
  secret_id     = aws_secretsmanager_secret.wordpress_salts.id
  secret_string = jsonencode({ for k, v in random_password.wordpress_salt : k => v.result })
}
