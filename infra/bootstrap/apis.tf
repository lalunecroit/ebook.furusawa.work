# プロジェクトで使う API の有効化。
#
# ここを bootstrap 側に置くのは、apply の直後は有効化が伝播しておらず、
# 続けて作るリソースが `API has not been used...` で落ちることがあるため。
# 先に bootstrap を流しておけば、この揺れを踏まない。
#
# なお bootstrap 自体が使う 2 つはここに書いていない。
#   storage.googleapis.com      … このバケットを作るのに要る
#   serviceusage.googleapis.com … 下の API 有効化そのものに要る
# どちらも新規プロジェクトで既定有効なので、手で叩く必要はない。
# (このプロジェクトでも有効であることを確認済み)

locals {
  services = [
    "compute.googleapis.com",              # LB / IP / 証明書
    "run.googleapis.com",                  # Cloud Run
    "sqladmin.googleapis.com",             # Cloud SQL
    "secretmanager.googleapis.com",        # Secret Manager
    "artifactregistry.googleapis.com",     # コンテナレジストリ
    "dns.googleapis.com",                  # Cloud DNS
    "iam.googleapis.com",                  # サービスアカウント
    "cloudresourcemanager.googleapis.com", # プロジェクトへの IAM 付与
  ]
}

resource "google_project_service" "this" {
  for_each = toset(local.services)

  project = var.project_id
  service = each.value

  # destroy のときに API を無効化しない。
  # 無効化すると、その API に依存する他のリソースまで巻き込んで壊れる。
  # API が有効なままでも課金は発生しない。
  disable_on_destroy = false
}
