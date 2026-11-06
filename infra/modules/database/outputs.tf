# インスタンスを消している間も Cloud Run の設定が変わらないよう、
# 名前や接続名はリソースからではなく入力から組み立てる。

output "instance_name" {
  description = "Cloud SQL インスタンス名"
  value       = var.instance_name
}

output "connection_name" {
  description = <<-EOT
    <project>:<region>:<instance> の形。Cloud Run のソケットマウントと
    DB_SOCKET (/cloudsql/<connection_name>) に使う。
  EOT
  value       = "${var.project_id}:${var.region}:${var.instance_name}"
}

output "database_name" {
  description = "アプリが使うデータベース名"
  value       = var.database_name
}

output "database_user" {
  description = "アプリが使う DB ユーザ名"
  value       = var.database_user
}

output "export_bucket" {
  description = "SQL ダンプの書き出し先のバケット"
  value       = google_storage_bucket.export.name
}

output "password_secret_id" {
  description = "DB パスワードの Secret。Cloud Run の secret_key_ref に渡す"
  value       = google_secret_manager_secret.db_password.secret_id
}

output "app_key_secret_id" {
  description = "APP_KEY の Secret。値は手で投入する"
  value       = google_secret_manager_secret.app_key.secret_id
}
