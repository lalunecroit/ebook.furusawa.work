# Cloud SQL (MySQL) と、アプリが使う DB / ユーザ / パスワード。
#
# Cloud Run からは VPC を通さず Unix ソケット (/cloudsql/<connection_name>) で繋ぐ。
# そのため private IP も Serverless VPC Access コネクタも要らない。

resource "google_sql_database_instance" "main" {
  project          = var.project_id
  name             = var.instance_name
  region           = var.region
  database_version = var.database_version

  # 消えると復旧できないので既定で守る。壊す前提で使うときだけ false にする。
  deletion_protection = var.deletion_protection

  settings {
    # エディションは必ず明示する。省略すると API がバージョンを見て勝手に選び、
    # MYSQL_8_4 では ENTERPRISE_PLUS になって db-f1-micro が弾かれる。
    edition = var.edition

    tier              = var.tier
    availability_type = "ZONAL" # REGIONAL にすると冗長化される代わりに倍額
    disk_size         = var.disk_size_gb
    disk_type         = var.disk_type

    # 10GiB の下限に張り付けているので、自動拡張は有効にしておく。
    # 上限を置かないと際限なく増えるため上限も決める。
    disk_autoresize       = true
    disk_autoresize_limit = 50

    ip_configuration {
      # パブリック IP は持たせる。
      #
      # Cloud Run の Unix ソケット接続は内部で Cloud SQL Auth Proxy が動いており、
      # 実際のデータ通信はインスタンスの IP に対して行われる。プライベート IP を
      # 使うには VPC と Direct VPC egress が要るので、IP を消すと繋がらなくなる。
      ipv4_enabled = true

      # 危険なのは IP を持つことではなく、許可リストを開けること。
      # ここを空のままにすると、パスワードを知っていても素の mysql -h <IP> は通らない。
      # 接続できるのは Cloud SQL Auth Proxy 経由だけで、利用には
      # roles/cloudsql.client が要る (認証をネットワーク層ではなく IAM に寄せる)。
      #
      # authorized_networks は意図的に書かない。0.0.0.0/0 を入れたら台無しになる。

      ssl_mode = "ENCRYPTED_ONLY"
    }

    backup_configuration {
      enabled            = true
      start_time         = "18:00" # UTC。JST の 03:00
      binary_log_enabled = true    # ポイントインタイム復旧に要る

      backup_retention_settings {
        retained_backups = 7
        retention_unit   = "COUNT"
      }
    }

    maintenance_window {
      day          = 7  # 日曜
      hour         = 19 # UTC。JST の月曜 04:00
      update_track = "stable"
    }

    # ローカルの compose と時刻の扱いを揃える (Step.03 でやったのと同じ)
    database_flags {
      name  = "default_time_zone"
      value = "+09:00"
    }

    user_labels = {
      managed_by = "terraform"
      component  = "database"
    }
  }
}

resource "google_sql_database" "app" {
  project  = var.project_id
  name     = var.database_name
  instance = google_sql_database_instance.main.name

  # Laravel のマイグレーションに合わせる
  charset   = "utf8mb4"
  collation = "utf8mb4_unicode_ci"
}

# --------------------------------------------------------------- パスワード
# Terraform が google_sql_user を作る以上、値は state に載る。
# 手投入した値を data で読み戻しても同じなので、生成まで Terraform に任せる。
# tfstate バケットを非公開に保つことが前提 (bootstrap 側で担保している)。

resource "random_password" "db" {
  length = 32

  # MySQL の接続文字列や環境変数で扱うため、記号は入れない
  special = false
}

resource "google_sql_user" "app" {
  project  = var.project_id
  name     = var.database_user
  instance = google_sql_database_instance.main.name
  password = random_password.db.result

  # ソケット経由なのでホスト制限は使わない (MySQL の '%' 相当)
  host = ""
}

# --------------------------------------------------------------- Secret
# アプリには値ではなく Secret の参照を渡す。
# Cloud Run 側で secret_key_ref を使うので、環境変数に平文は出ない。

resource "google_secret_manager_secret" "db_password" {
  project   = var.project_id
  secret_id = "ebook-db-password"

  replication {
    auto {}
  }

  labels = {
    managed_by = "terraform"
    component  = "database"
  }
}

resource "google_secret_manager_secret_version" "db_password" {
  secret      = google_secret_manager_secret.db_password.id
  secret_data = random_password.db.result
}

# APP_KEY は Terraform 側に値を使う相手がいない (Cloud Run が参照するだけ)。
# 箱だけ作り、値は手で入れる。こうすると state には残らない。
#
#   php artisan key:generate --show | \
#     gcloud secrets versions add ebook-app-key --data-file=-
resource "google_secret_manager_secret" "app_key" {
  project   = var.project_id
  secret_id = "ebook-app-key"

  replication {
    auto {}
  }

  labels = {
    managed_by = "terraform"
    component  = "database"
  }
}
