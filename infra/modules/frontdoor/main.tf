# インターネットからの入口。
#
# LB は 1 台だけ。サブドメインで役割を分けるが、振り分けは URL マップの
# host_rule が担当するので、IP も転送ルールも証明書も増えない (step07 の 0 章)。

locals {
  host = {
    www   = "www.${var.domain}"
    api   = "api.${var.domain}"
    admin = "admin.${var.domain}"
    cdn   = "cdn.${var.domain}"
    docs  = "docs.${var.domain}"
  }

  # 証明書に載せるドメイン。apex を含めて 6 つ
  cert_domains = concat([var.domain], values(local.host))

  # A レコードを作る名前。apex は "" ではなく domain そのもの
  dns_names = merge(local.host, { apex = var.domain })
}

# --------------------------------------------------------------- IP
resource "google_compute_global_address" "lb" {
  project = var.project_id
  name    = "${var.name}-lb"
}

# --------------------------------------------------------------- Cloud Run
# Serverless NEG は Cloud Run を LB のバックエンドとして扱うための受け口。
resource "google_compute_region_network_endpoint_group" "api" {
  project               = var.project_id
  name                  = "${var.name}-api-neg"
  region                = var.region
  network_endpoint_type = "SERVERLESS"

  cloud_run {
    service = var.api_service_name
  }
}

resource "google_compute_region_network_endpoint_group" "admin" {
  project               = var.project_id
  name                  = "${var.name}-admin-neg"
  region                = var.region
  network_endpoint_type = "SERVERLESS"

  cloud_run {
    service = var.admin_service_name
  }
}

# ヘルスチェックは付けない。Serverless NEG は対象外で、付けるとエラーになる。
# Cloud Run 側の可用性は Google が見る (step07 の 4.6)。
resource "google_compute_backend_service" "api" {
  project               = var.project_id
  name                  = "${var.name}-api"
  load_balancing_scheme = "EXTERNAL_MANAGED"

  backend {
    group = google_compute_region_network_endpoint_group.api.id
  }
}

resource "google_compute_backend_service" "admin" {
  project               = var.project_id
  name                  = "${var.name}-admin"
  load_balancing_scheme = "EXTERNAL_MANAGED"

  backend {
    group = google_compute_region_network_endpoint_group.admin.id
  }
}

# --------------------------------------------------------------- 振り分け
resource "google_compute_url_map" "main" {
  project = var.project_id
  name    = var.name

  # どの host_rule にも当たらなかったとき (IP 直打ちなど) の行き先。
  # 置かないと 404 ではなく LB の設定エラーになる (step07 の 4.6)。
  default_service = var.frontend_backend_bucket_id

  # ホスト名で分けるだけなので path_rule は 1 つも書かない
  host_rule {
    hosts        = [local.host.www]
    path_matcher = "frontend"
  }
  host_rule {
    hosts        = [local.host.api]
    path_matcher = "api"
  }
  host_rule {
    hosts        = [local.host.admin]
    path_matcher = "admin"
  }
  host_rule {
    hosts        = [local.host.cdn]
    path_matcher = "cdn"
  }
  host_rule {
    hosts        = [local.host.docs]
    path_matcher = "docs"
  }
  host_rule {
    hosts        = [var.domain]
    path_matcher = "apex"
  }

  path_matcher {
    name            = "frontend"
    default_service = var.frontend_backend_bucket_id
  }
  path_matcher {
    name            = "api"
    default_service = google_compute_backend_service.api.id
  }
  path_matcher {
    name            = "admin"
    default_service = google_compute_backend_service.admin.id
  }
  path_matcher {
    name            = "cdn"
    default_service = var.cdn_backend_bucket_id
  }
  path_matcher {
    name            = "docs"
    default_service = var.docs_backend_bucket_id
  }

  # apex は www へ寄せる。同じ内容が 2 つの URL で見える状態を作らない
  path_matcher {
    name = "apex"
    default_url_redirect {
      host_redirect          = local.host.www
      https_redirect         = true
      redirect_response_code = "MOVED_PERMANENTLY_DEFAULT" # 301
      strip_query            = false
    }
  }
}

# --------------------------------------------------------------- 証明書
resource "google_compute_managed_ssl_certificate" "main" {
  project = var.project_id

  # domains を変えると証明書は作り直しになる。create_before_destroy と
  # 組み合わせるため、名前にドメイン一覧のハッシュを入れて衝突を避ける。
  name = "${var.name}-cert-${substr(sha1(join(",", local.cert_domains)), 0, 8)}"

  managed {
    domains = local.cert_domains
  }

  lifecycle {
    create_before_destroy = true
  }
}

resource "google_compute_target_https_proxy" "main" {
  project          = var.project_id
  name             = var.name
  url_map          = google_compute_url_map.main.id
  ssl_certificates = [google_compute_managed_ssl_certificate.main.id]
}

resource "google_compute_global_forwarding_rule" "https" {
  project               = var.project_id
  name                  = "${var.name}-https"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  ip_address            = google_compute_global_address.lb.id
  port_range            = "443"
  target                = google_compute_target_https_proxy.main.id
}

# --------------------------------------------------------------- 80 番
# 転送ルールは先頭 5 本まで同じ値段なので、この 2 本目で費用は増えない。
resource "google_compute_url_map" "redirect" {
  project = var.project_id
  name    = "${var.name}-redirect"

  default_url_redirect {
    https_redirect         = true
    redirect_response_code = "MOVED_PERMANENTLY_DEFAULT"
    strip_query            = false
  }
}

resource "google_compute_target_http_proxy" "redirect" {
  project = var.project_id
  name    = "${var.name}-redirect"
  url_map = google_compute_url_map.redirect.id
}

resource "google_compute_global_forwarding_rule" "http" {
  project               = var.project_id
  name                  = "${var.name}-http"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  ip_address            = google_compute_global_address.lb.id
  port_range            = "80"
  target                = google_compute_target_http_proxy.redirect.id
}

# --------------------------------------------------------------- DNS
# ゾーンは手で作られたもので Terraform の管理外。参照するだけにする (step08 の 5.1)。
data "google_dns_managed_zone" "root" {
  project = var.project_id
  name    = var.dns_zone_name
}

resource "google_dns_record_set" "a" {
  for_each = local.dns_names

  project      = var.project_id
  managed_zone = data.google_dns_managed_zone.root.name
  name         = "${each.value}."
  type         = "A"
  ttl          = var.dns_ttl
  rrdatas      = [google_compute_global_address.lb.address]
}
