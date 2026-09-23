output "registry_host" {
  description = "gcloud auth configure-docker に渡すホスト名"
  value       = module.registry.host
}

output "registry_url" {
  description = "イメージ名の接頭辞。<registry_url>/api:<tag> の形で push する"
  value       = module.registry.url
}

output "db_connection_name" {
  description = "Cloud Run のソケットマウントと DB_SOCKET に使う"
  value       = module.database.connection_name
}

output "db_password_secret_id" {
  description = "DB パスワードの Secret 名"
  value       = module.database.password_secret_id
}

output "app_key_secret_id" {
  description = "APP_KEY の Secret 名。値は手で投入する"
  value       = module.database.app_key_secret_id
}
