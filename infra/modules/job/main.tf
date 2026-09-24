# 単発で流す artisan コマンド (Cloud Run Jobs)。
#
# マイグレーションをコンテナの起動時に流さないのは、Cloud Run では
# インスタンスが同時に複数立ち、起動のたびに migrate すると競合するため
# (step07 の 10)。同じイメージを Job として別に起動する。
#
# 定義を Terraform に置くのは、Job が「何度も実行するもの」だからで、
# gcloud で作ると引数や環境変数が手元の履歴にしか残らない。

resource "google_service_account" "this" {
  project      = var.project_id
  account_id   = var.name
  display_name = "${var.name} (Cloud Run Job)"
}

resource "google_project_iam_member" "cloudsql_client" {
  project = var.project_id
  role    = "roles/cloudsql.client"
  member  = "serviceAccount:${google_service_account.this.email}"
}

resource "google_secret_manager_secret_iam_member" "accessor" {
  for_each = toset(values(var.secret_env))

  project   = var.project_id
  secret_id = each.value
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.this.email}"
}

resource "google_cloud_run_v2_job" "this" {
  project  = var.project_id
  name     = var.name
  location = var.region

  # 実行するだけのものなので、destroy を止める必要はない
  deletion_protection = false

  template {
    template {
      service_account = google_service_account.this.email
      max_retries     = var.max_retries
      timeout         = var.timeout

      volumes {
        name = "cloudsql"
        cloud_sql_instance {
          instances = [var.cloudsql_connection_name]
        }
      }

      containers {
        image   = var.image
        command = var.command
        args    = var.args

        volume_mounts {
          name       = "cloudsql"
          mount_path = "/cloudsql"
        }

        resources {
          limits = {
            cpu    = var.cpu
            memory = var.memory
          }
        }

        dynamic "env" {
          for_each = var.env
          content {
            name  = env.key
            value = env.value
          }
        }

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
  }

  # イメージの更新は CI の仕事。サービス側と同じ扱いにする
  lifecycle {
    ignore_changes = [template[0].template[0].containers[0].image]
  }

  depends_on = [
    google_secret_manager_secret_iam_member.accessor,
    google_project_iam_member.cloudsql_client,
  ]
}
