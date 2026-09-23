output "bucket_name" {
  description = "gcloud storage rsync の宛先"
  value       = google_storage_bucket.this.name
}

output "bucket_url" {
  description = "gs:// 形式の URL"
  value       = google_storage_bucket.this.url
}

output "backend_bucket_id" {
  description = "URL マップから参照する backend bucket の ID"
  value       = google_compute_backend_bucket.this.id
}
