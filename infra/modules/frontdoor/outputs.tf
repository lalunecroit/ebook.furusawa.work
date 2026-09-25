output "ip_address" {
  description = "LB のグローバル静的 IP。全ホストの A レコードがこれを指す"
  value       = google_compute_global_address.lb.address
}

output "certificate_name" {
  description = "マネージド証明書。ACTIVE になるまで 15〜60 分かかる"
  value       = google_compute_managed_ssl_certificate.main.name
}

output "certificate_domains" {
  description = "証明書に載せたドメイン。1 つでも DNS が解決しないと発行されない"
  value       = google_compute_managed_ssl_certificate.main.managed[0].domains
}

output "urls" {
  description = "公開 URL"
  value = {
    www   = "https://www.${var.domain}"
    api   = "https://api.${var.domain}"
    admin = "https://admin.${var.domain}"
    cdn   = "https://cdn.${var.domain}"
    docs  = "https://docs.${var.domain}"
  }
}

output "url_map_name" {
  description = "Cloud CDN のキャッシュ無効化 (gcloud compute url-maps invalidate-cdn-cache) に渡す"
  value       = google_compute_url_map.main.name
}
