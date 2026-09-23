# 本番環境。modules/ の部品を組み合わせる。
#
# 段階的に書き足して、その都度 terraform apply する。
# Cloud Run はイメージが無いと作れないので、registry を先に作って
# docker push を済ませてから api / admin を書き足した。

locals {
  # ホスト名。DNS レコードも証明書もこれを元にする
  host = {
    www   = "www.${var.domain}"
    api   = "api.${var.domain}"
    admin = "admin.${var.domain}"
    cdn   = "cdn.${var.domain}"
    docs  = "docs.${var.domain}"
  }

  # api と admin で共通の環境変数。
  # 値の意味は step07 の 6 章 (アプリ側に必要な変更) を参照。
  common_env = {
    APP_ENV      = "production"
    APP_DEBUG    = "false"
    APP_TIMEZONE = "Asia/Tokyo"

    # ソケット経由なので DB_HOST は使わない
    DB_CONNECTION = "mysql"
    DB_SOCKET     = "/cloudsql/${module.database.connection_name}"
    DB_DATABASE   = module.database.database_name
    DB_USERNAME   = module.database.database_user
    DB_TIMEZONE   = "+09:00"

    # Cloud Run はインスタンスが使い捨てで複数立つ。file ドライバだと
    # ログイン状態が保てない (step08 の 6 章)
    SESSION_DRIVER        = "database"
    SESSION_SECURE_COOKIE = "true"

    # 画像の配り元。組み立てロジックは step02 のまま
    CDN_BASE_URL = "https://${local.host.cdn}"

    # www から api への fetch はクロスオリジンになる
    CORS_ALLOWED_ORIGINS = "https://${local.host.www}"

    # 管理画面をこのホストだけに限定する。
    # api 側にも渡すことで、api.<domain>/admin/* が 404 になる
    ADMIN_HOST = local.host.admin

    # 画像の保存先をローカルディスクから GCS に差し替える。
    # 実際に書けるのは storage.objectAdmin を持つ admin だけ
    CDN_DISK             = "gcs"
    CDN_BUCKET           = module.cdn.bucket_name
    GOOGLE_CLOUD_PROJECT = var.project_id
  }

  # Secret Manager から渡すもの。値ではなく参照
  common_secret_env = {
    APP_KEY     = module.database.app_key_secret_id
    DB_PASSWORD = module.database.password_secret_id
  }
}

# --------------------------------------------------------------- A段
module "registry" {
  source = "../../modules/registry"

  project_id = var.project_id
  region     = var.region
}

# --------------------------------------------------------------- B段
# ここから固定費が発生する (step07 の 7 章: 約 1,800 円/月)。
module "database" {
  source = "../../modules/database"

  project_id = var.project_id
  region     = var.region
}

# --------------------------------------------------------------- C段
# 静的バケット 3 本。バケット自体はほぼ無料だが、admin の SA に
# cdn への書き込み権限を付ける必要があるので Cloud Run と同じ段で作る。

module "frontend" {
  source = "../../modules/static_site"

  project_id = var.project_id
  name       = "${var.project_id}-frontend"
  component  = "frontend"

  # HTML や JS は書き換えるので短めに
  default_ttl = 300
  client_ttl  = 300
}

module "cdn" {
  source = "../../modules/static_site"

  project_id = var.project_id
  name       = "${var.project_id}-cdn"
  component  = "cdn"

  # ページ画像は差し替え時に ?v= が変わるので、長く持たせてよい (step02)
  default_ttl = 31536000
  client_ttl  = 31536000

  # <img src> で読むだけなら不要だが、将来 canvas や fetch を使う場合に備える
  cors_origins = ["https://${local.host.www}"]
}

module "docs" {
  source = "../../modules/static_site"

  project_id = var.project_id
  name       = "${var.project_id}-docs"
  component  = "docs"

  # 書き換えの多いドキュメントに長い TTL は向かない
  default_ttl = 300
  client_ttl  = 300
}

module "api" {
  source = "../../modules/api_service"

  project_id = var.project_id
  region     = var.region
  name       = "ebook-api"
  image      = "${module.registry.url}/api:v1"

  cloudsql_connection_name = module.database.connection_name
  min_instances            = var.min_instances

  env = merge(local.common_env, {
    APP_URL = "https://${local.host.api}"
  })
  secret_env = local.common_secret_env

  # 公開 API には書き込み権限を与えない
  cdn_bucket_writer = null
}

module "admin" {
  source = "../../modules/api_service"

  project_id = var.project_id
  region     = var.region
  name       = "ebook-admin"
  image      = "${module.registry.url}/api:v1" # api と同じイメージ

  cloudsql_connection_name = module.database.connection_name
  min_instances            = var.min_instances

  env = merge(local.common_env, {
    APP_URL = "https://${local.host.admin}"

    # Cookie を admin ホストに閉じる
    SESSION_DOMAIN = local.host.admin
  })
  secret_env = local.common_secret_env

  # 画像を書けるのはこちらだけ
  cdn_bucket_writer = module.cdn.bucket_name
}

# --------------------------------------------------------------- 単発実行
# マイグレーションはコンテナ起動時には流さず、Job として別に実行する
# (step07 の 10)。作るだけでは何も起きず、実行は明示的に叩いたときだけ:
#   gcloud run jobs execute ebook-migrate --region asia-northeast1 --wait
module "migrate_job" {
  source = "../../modules/job"

  project_id = var.project_id
  region     = var.region
  name       = "ebook-migrate"
  image      = "${module.registry.url}/api:v1"

  args = ["artisan", "migrate", "--force"]

  cloudsql_connection_name = module.database.connection_name

  # oneshot モードなので Web 用の変数 (CDN_BASE_URL など) は要らない。
  # APP_KEY は entrypoint.sh が起動時に必ず要求する
  env = {
    APP_ENV       = "production"
    APP_DEBUG     = "false"
    APP_TIMEZONE  = "Asia/Tokyo"
    DB_CONNECTION = "mysql"
    DB_SOCKET     = "/cloudsql/${module.database.connection_name}"
    DB_DATABASE   = module.database.database_name
    DB_USERNAME   = module.database.database_user
    DB_TIMEZONE   = "+09:00"
  }
  secret_env = local.common_secret_env
}
