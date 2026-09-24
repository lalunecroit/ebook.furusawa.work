output "instance_name" {
  description = "Cloud SQL インスタンス名"
  value       = google_sql_database_instance.main.name
}

output "connection_name" {
  description = <<-EOT
    <project>:<region>:<instance> の形。Cloud Run のソケットマウントと
    DB_SOCKET (/cloudsql/<connection_name>) に使う。
  EOT
  value       = google_sql_database_instance.main.connection_name
}

output "database_name" {
  description = "アプリが使うデータベース名"
  value       = google_sql_database.app.name
}

output "database_user" {
  description = "アプリが使う DB ユーザ名"
  value       = google_sql_user.app.name
}

output "password_secret_id" {
  description = "DB パスワードの Secret。Cloud Run の secret_key_ref に渡す"
  value       = google_secret_manager_secret.db_password.secret_id
}

output "app_key_secret_id" {
  description = "APP_KEY の Secret。値は手で投入する"
  value       = google_secret_manager_secret.app_key.secret_id
}
