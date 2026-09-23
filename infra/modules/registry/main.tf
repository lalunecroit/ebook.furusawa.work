# コンテナイメージの置き場 (Artifact Registry)。
#
# Cloud Run は「イメージが既にある」ことを前提に作られるので、
# このリポジトリだけ先に apply し、docker push を済ませてから残りを流す。
# その順番のために、他のリソースと独立したモジュールにしてある。

resource "google_artifact_registry_repository" "this" {
  project       = var.project_id
  location      = var.region
  repository_id = var.repository_id
  format        = "DOCKER"
  description   = "ebook のコンテナイメージ"

  # 保持ポリシー。KEEP は DELETE より優先されるので、
  # 「直近 N 世代は必ず残したうえで、タグ無しの古いものを消す」になる。
  cleanup_policies {
    id     = "keep-recent"
    action = "KEEP"
    most_recent_versions {
      keep_count = var.keep_versions
    }
  }

  cleanup_policies {
    id     = "delete-untagged"
    action = "DELETE"
    condition {
      tag_state  = "UNTAGGED"
      older_than = var.untagged_ttl
    }
  }

  # true にすると実際には消さず、消す対象をログに出すだけになる。
  # 挙動を確かめたいときはここを true にして Cloud Logging を見る。
  cleanup_policy_dry_run = false

  labels = {
    managed_by = "terraform"
    component  = "registry"
  }
}
