# Cloud Run のサービス 1 つと、それ専用のサービスアカウント。
#
# api と admin で 2 回呼ぶ。イメージは同じものを使い、環境変数と権限だけ変える。
# 分ける理由は処理の重さではなく権限で、cdn バケットへの書き込みを admin 側の
# SA にだけ持たせるため (step07 の 4.8)。

resource "google_service_account" "this" {
  project      = var.project_id
  account_id   = var.name
  display_name = "${var.name} (Cloud Run)"
}

# Cloud SQL へソケットで繋ぐのに要る
resource "google_project_iam_member" "cloudsql_client" {
  project = var.project_id
  role    = "roles/cloudsql.client"
  member  = "serviceAccount:${google_service_account.this.email}"
}

# 渡す Secret だけに読み取りを許す。プロジェクト全体には付けない。
resource "google_secret_manager_secret_iam_member" "accessor" {
  for_each = toset(values(var.secret_env))

  project   = var.project_id
  secret_id = each.value
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.this.email}"
}

# 画像の書き込み先。admin にだけ渡す (var.cdn_bucket_writer が null なら作らない)。
resource "google_storage_bucket_iam_member" "cdn_writer" {
  count = var.cdn_bucket_writer == null ? 0 : 1

  bucket = var.cdn_bucket_writer
  role   = "roles/storage.objectAdmin"
  member = "serviceAccount:${google_service_account.this.email}"
}

resource "google_cloud_run_v2_service" "this" {
  project  = var.project_id
  name     = var.name
  location = var.region

  # LB 経由だけを受ける。*.run.app の URL が直接叩けなくなり、
  # Cloud CDN を迂回される経路が消える (step07 の 4.2)。
  ingress = "INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER"

  deletion_protection = var.deletion_protection

  template {
    service_account = google_service_account.this.email

    scaling {
      min_instance_count = var.min_instances
      max_instance_count = var.max_instances
    }

    # VPC コネクタは使わず、ソケットをボリュームとしてマウントする。
    # コネクタの固定費 (月 1,500 円〜) が掛からない。
    volumes {
      name = "cloudsql"
      cloud_sql_instance {
        instances = [var.cloudsql_connection_name]
      }
    }

    containers {
      image = var.image

      # Cloud Run は $PORT を渡してくる。entrypoint.sh が nginx の設定に埋める
      ports {
        container_port = 8080
      }

      volume_mounts {
        name       = "cloudsql"
        mount_path = "/cloudsql"
      }

      resources {
        limits = {
          cpu    = var.cpu
          memory = var.memory
        }

        # リクエストを処理していない間は CPU を絞る。
        # min_instances = 1 でも待機中のぶんが安くなる。
        # バックグラウンド処理を持たないアプリなので影響はない。
        cpu_idle = true
      }

      dynamic "env" {
        for_each = var.env
        content {
          name  = env.key
          value = env.value
        }
      }

      # 値ではなく参照を渡す。リビジョンの設定画面に平文が出ない。
      dynamic "env" {
        for_each = var.secret_env
        content {
          name = env.key
          value_source {
            secret_key_ref {
              secret  = env.value
              version = "latest"
            }
          }
        }
      }
    }
  }

  # イメージの更新は CD の仕事。Terraform に差分として見せない (step07 の 4.2)。
  # client / client_version は gcloud でデプロイすると gcloud が書き込む印で、
  # 放っておくとデプロイのたびに plan に差分として出続ける。
  lifecycle {
    ignore_changes = [
      template[0].containers[0].image,
      client,
      client_version,
    ]
  }

  depends_on = [
    google_secret_manager_secret_iam_member.accessor,
    google_project_iam_member.cloudsql_client,
  ]
}

# LB から呼べるようにする。
# ingress で *.run.app を塞いであるので、これを付けても直接は叩けない。
resource "google_cloud_run_v2_service_iam_member" "invoker" {
  project  = var.project_id
  name     = google_cloud_run_v2_service.this.name
  location = google_cloud_run_v2_service.this.location
  role     = "roles/run.invoker"
  member   = "allUsers"
}
