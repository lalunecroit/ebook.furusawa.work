output "state_bucket" {
  description = "tfstate を置くバケット名。backend.tf にこの値を書く"
  value       = google_storage_bucket.tfstate.name
}

output "enabled_services" {
  description = "有効化した API"
  value       = sort([for s in google_project_service.this : s.service])
}
