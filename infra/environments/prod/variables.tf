variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "既定のリージョン。Cloud Run / Cloud SQL / Artifact Registry を揃える"
  type        = string
  default     = "asia-northeast1"
}

variable "domain" {
  description = "ルートドメイン。サブドメインはここから組み立てる"
  type        = string
  default     = "ebook.furusawa.work"
}

variable "min_instances" {
  description = <<-EOT
    Cloud Run の最小インスタンス数。
    0 ならコールドスタートを許容して安く、1 以上は常時課金。
    まず 1 で請求額を見てから判断する (step08 の 5.2)。
  EOT
  type        = number
  default     = 1
}

variable "dns_zone_name" {
  description = <<-EOT
    レコードを追加する Cloud DNS ゾーンの名前。
    ゾーン自体は Terraform の管理外で、参照するだけ (step08 の 5.1)。
  EOT
  type        = string
  default     = "furusawa-work"
}

variable "github_repository" {
  description = "GitHub リポジトリ (owner/name)"
  type        = string
}

variable "github_repository_owner_id" {
  description = <<-EOT
    リポジトリ所有者の数値 ID。CD 用の subject (不変 ID 付き) に要る。
      curl -s https://api.github.com/repos/<owner>/<name> | jq .owner.id
  EOT
  type        = string
}

variable "github_repository_id" {
  description = <<-EOT
    GitHub リポジトリの数値 ID。WIF の信頼判定に使う。
      curl -s https://api.github.com/repos/<owner>/<name> | jq .id
  EOT
  type        = string
}
