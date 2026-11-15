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
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "wordpress_salts" {
  secret_id     = aws_secretsmanager_secret.wordpress_salts.id
  secret_string = jsonencode({ for k, v in random_password.wordpress_salt : k => v.result })
}

resource "random_password" "wp_admin" {
  length  = 24
  special = false
}

resource "aws_secretsmanager_secret" "wp_admin" {
  name                    = "${local.name_prefix}/wordpress-admin"
  description             = "Initial WordPress admin password (used by first-boot install)"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "wp_admin" {
  secret_id     = aws_secretsmanager_secret.wp_admin.id
  secret_string = jsonencode({ password = random_password.wp_admin.result })
}

# Claude API key for the ASC Quick Publish "Generate with AI" button.
# Terraform only creates the secret with a placeholder; the real key is set by
# hand (console or CLI), so it never appears in Git or in the Terraform state.
# ignore_changes stops Terraform from overwriting the real key on later applies.
resource "aws_secretsmanager_secret" "anthropic_api_key" {
  name                    = "${local.name_prefix}/anthropic-api-key"
  description             = "Claude API key (plain string) for the ASC Quick Publish plugin"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "anthropic_api_key" {
  secret_id     = aws_secretsmanager_secret.anthropic_api_key.id
  secret_string = "not-set"

  lifecycle {
    ignore_changes = [secret_string]
  }
}
