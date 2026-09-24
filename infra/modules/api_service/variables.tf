variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "Cloud Run のリージョン。Cloud SQL と揃える"
  type        = string
}

variable "name" {
  description = "サービス名 (ebook-api / ebook-admin)。SA 名もこれを元に作る"
  type        = string
}

variable "image" {
  description = <<-EOT
    コンテナイメージ。api と admin で同じものを指す (step07 の 4.8)。
    以降の更新は CI の仕事なので、Terraform は差分として見ない。
  EOT
  type        = string
}

variable "env" {
  description = "平文で渡す環境変数"
  type        = map(string)
  default     = {}
}

variable "secret_env" {
  description = <<-EOT
    Secret Manager から渡す環境変数。{ 環境変数名 = Secret ID }。
    値ではなく参照を渡すので、リビジョン設定に平文が残らない。
  EOT
  type        = map(string)
  default     = {}
}

variable "cloudsql_connection_name" {
  description = "<project>:<region>:<instance>。ソケットのマウントに使う"
  type        = string
}

variable "cdn_bucket_writer" {
  description = <<-EOT
    書き込み権限を与える GCS バケット名。admin にだけ渡す。
    公開 API 側に渡さないことで、踏み台にされても画像を書き換えられない。
  EOT
  type        = string
  default     = null
}

variable "min_instances" {
  description = <<-EOT
    0 にするとリクエストが無い間は落ち、次のアクセスでコールドスタートになる。
    1 以上は常時課金。まず 1 で様子を見る (step08 の 5.2)。
  EOT
  type        = number
  default     = 1
}

variable "max_instances" {
  description = "上限。置かないと事故や攻撃で青天井に増える"
  type        = number
  default     = 4
}

variable "cpu" {
  description = "1 コンテナあたりの CPU"
  type        = string
  default     = "1"
}

variable "memory" {
  description = "1 コンテナあたりのメモリ。nginx + php-fpm を supervisord で同居させている"
  type        = string
  default     = "512Mi"
}

variable "deletion_protection" {
  description = "true にすると terraform destroy を拒否する。建てて壊す運用なので既定は false"
  type        = bool
  default     = false
}
