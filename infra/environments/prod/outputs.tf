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

output "buckets" {
  description = "gcloud storage rsync の宛先"
  value = {
    frontend = module.frontend.bucket_name
    cdn      = module.cdn.bucket_name
    docs     = module.docs.bucket_name
  }
}

output "cloud_run" {
  description = "Cloud Run のサービス。ingress を LB 限定にしてあるので uri は直接叩けない"
  value = {
    api = {
      name = module.api.name
      uri  = module.api.uri
      sa   = module.api.service_account_email
    }
    admin = {
      name = module.admin.name
      uri  = module.admin.uri
      sa   = module.admin.service_account_email
    }
  }
}

output "migrate_job" {
  description = "マイグレーション用の Cloud Run Job。作成しただけでは実行されない"
  value       = module.migrate_job.name
}
