output "name" {
  description = "Cloud Run のサービス名"
  value       = google_cloud_run_v2_service.this.name
}

output "location" {
  description = "リージョン。Serverless NEG の作成に使う"
  value       = google_cloud_run_v2_service.this.location
}

output "uri" {
  description = <<-EOT
    *.run.app の URL。ingress を LB 限定にしてあるので直接は叩けない。
    ログや疎通確認の目印として出しておく。
  EOT
  value       = google_cloud_run_v2_service.this.uri
}

output "service_account_email" {
  description = "このサービスの SA"
  value       = google_service_account.this.email
}
