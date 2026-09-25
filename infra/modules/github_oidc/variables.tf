variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "repository" {
  description = "owner/name 形式。表示とラベル用で、信頼の判定には repository_id を使う"
  type        = string
}

variable "repository_id" {
  description = <<-EOT
    GitHub リポジトリの数値 ID。信頼の判定はこちらで行う。
    名前 (owner/name) は消して作り直すと他人が同じ名前を取れるが、
    数値 ID は作り直すと変わるため、乗っ取られた同名リポジトリを信頼せずに済む。
      curl -s https://api.github.com/repos/<owner>/<name> | jq .id
  EOT
  type        = string
}

variable "deploy_branch" {
  description = "デプロイ権限を使えるブランチ。PR のブランチからは本番を触らせない"
  type        = string
  default     = "main"
}

# --------------------------------------------------------------- deployer が触るもの

variable "region" {
  description = "Artifact Registry と Cloud Run のリージョン"
  type        = string
}

variable "artifact_registry_repository" {
  description = "イメージを push するリポジトリ名"
  type        = string
}

variable "cloud_run_services" {
  description = "イメージを差し替える Cloud Run サービス名"
  type        = list(string)
}

variable "cloud_run_jobs" {
  description = "イメージを差し替えて実行する Cloud Run Jobs 名"
  type        = list(string)
}

variable "runtime_service_accounts" {
  description = <<-EOT
    上のサービスと Job が実行時に使う SA のメールアドレス。
    新しいリビジョンを「その SA として動かす」には、デプロイする側が
    その SA に対する iam.serviceAccounts.actAs を持っている必要がある。
  EOT
  type        = list(string)
}

variable "deploy_buckets" {
  description = <<-EOT
    静的ファイルを rsync するバケット名。
    cdn は入れない。本番の画像は管理画面からアップロードされるもので、
    リポジトリの中身 (サンプル) で上書きしたくないため。
  EOT
  type        = list(string)
}

# --------------------------------------------------------------- planner が読むもの

variable "state_bucket" {
  description = "tfstate を置いているバケット名。plan で読む"
  type        = string
}

variable "plan_readable_secrets" {
  description = <<-EOT
    plan の refresh で値を読む Secret。
    Terraform が値を管理している google_secret_manager_secret_version は、
    差分を取るために実際の値を読みにいくため。
  EOT
  type        = list(string)
  default     = []
}
