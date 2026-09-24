output "name" {
  description = "Job 名。gcloud run jobs execute に渡す"
  value       = google_cloud_run_v2_job.this.name
}

output "service_account_email" {
  description = "この Job の SA"
  value       = google_service_account.this.email
}
