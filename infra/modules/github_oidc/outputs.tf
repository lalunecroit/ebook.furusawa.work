# どれも秘密ではない。workflow にそのまま書いてよい値。

output "workload_identity_provider" {
  description = "google-github-actions/auth の workload_identity_provider に渡す"
  value       = google_iam_workload_identity_pool_provider.github.name
}

output "deployer_service_account" {
  description = "CD の workflow で使う SA"
  value       = google_service_account.deployer.email
}

output "planner_service_account" {
  description = "CI の workflow (terraform plan) で使う SA"
  value       = google_service_account.planner.email
}
