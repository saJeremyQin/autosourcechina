output "db_host" {
  description = "RDS endpoint hostname."
  value       = aws_db_instance.this.address
}

output "db_port" {
  value = aws_db_instance.this.port
}

output "db_name" {
  value = aws_db_instance.this.db_name
}

output "db_username" {
  value = aws_db_instance.this.username
}

output "db_password_secret_arn" {
  description = "Secrets Manager secret holding the master password (JSON: username, password)."
  value       = aws_secretsmanager_secret.db.arn
}
