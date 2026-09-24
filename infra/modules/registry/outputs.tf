output "repository_id" {
  description = "リポジトリ名"
  value       = google_artifact_registry_repository.this.repository_id
}

output "host" {
  description = "docker login / configure-docker に渡すホスト名"
  value       = "${var.region}-docker.pkg.dev"
}

output "url" {
  description = "イメージ名の接頭辞。<url>/<image>:<tag> の形で push する"
  value       = "${var.region}-docker.pkg.dev/${var.project_id}/${google_artifact_registry_repository.this.repository_id}"
}
