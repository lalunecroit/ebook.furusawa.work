# 静的ファイルを配る GCS バケットと、その LB 側の受け口 (backend bucket)。
#
# 器だけを Terraform が持ち、中身は gcloud storage rsync で入れる (step07 の 4.5)。
# ファイルを google_storage_bucket_object で 1 つずつ書くと、ページが増えるたびに
# plan が膨らみ、アプリのデプロイとインフラの変更が同じ apply に混ざる。

resource "google_storage_bucket" "this" {
  project       = var.project_id
  name          = var.name
  location      = var.location
  force_destroy = var.force_destroy

  # オブジェクト単位の ACL を使わず IAM だけで制御する。
  # 下の allUsers 付与も IAM 側で行う。
  uniform_bucket_level_access = true

  # ディレクトリ URL でのアクセスに index.html を返す。
  # ただし LB 経由でこれが効くかは要検証 (step07 の 8 章)。
  website {
    main_page_suffix = "index.html"
    not_found_page   = "index.html"
  }

  dynamic "cors" {
    for_each = length(var.cors_origins) > 0 ? [1] : []
    content {
      origin          = var.cors_origins
      method          = ["GET", "HEAD"]
      response_header = ["Content-Type"]
      max_age_seconds = 3600
    }
  }

  labels = {
    managed_by = "terraform"
    component  = var.component
  }
}

# 公開読み取り。書き込み権限はここでは一切与えない。
# cdn バケットへの書き込みは admin の SA にだけ付ける (modules/api_service)。
resource "google_storage_bucket_iam_member" "public_read" {
  bucket = google_storage_bucket.this.name
  role   = "roles/storage.objectViewer"
  member = "allUsers"
}

# LB から見たときの受け口。Cloud CDN はここで有効にする。
resource "google_compute_backend_bucket" "this" {
  project     = var.project_id
  name        = var.name
  bucket_name = google_storage_bucket.this.name
  enable_cdn  = true

  cdn_policy {
    cache_mode  = "CACHE_ALL_STATIC"
    default_ttl = var.default_ttl
    client_ttl  = var.client_ttl
    max_ttl     = 31536000

    # 同じオブジェクトへの同時アクセスをオリジンに素通しさせない
    request_coalescing = true
  }
}
