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

output "lb_ip_address" {
  description = "LB のグローバル静的 IP"
  value       = module.frontdoor.ip_address
}

output "certificate" {
  description = "マネージド証明書。ACTIVE になるまで 15〜60 分かかる"
  value = {
    name    = module.frontdoor.certificate_name
    domains = module.frontdoor.certificate_domains
  }
}

output "urls" {
  description = "公開 URL"
  value       = module.frontdoor.urls
}

output "github_actions" {
  description = "workflow に書く値。どれも秘密ではない"
  value = {
    workload_identity_provider = module.github_oidc.workload_identity_provider
    deployer_service_account   = module.github_oidc.deployer_service_account
    planner_service_account    = module.github_oidc.planner_service_account
    registry_url               = module.registry.url
    url_map                    = module.frontdoor.url_map_name
  }
}
