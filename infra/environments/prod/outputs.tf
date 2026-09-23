output "registry_host" {
  description = "gcloud auth configure-docker に渡すホスト名"
  value       = module.registry.host
}

output "registry_url" {
  description = "イメージ名の接頭辞。<registry_url>/api:<tag> の形で push する"
  value       = module.registry.url
}
