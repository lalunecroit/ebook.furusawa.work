variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "リージョン。Cloud SQL と揃える"
  type        = string
}

variable "name" {
  description = "Job 名。SA 名もこれを元に作る"
  type        = string
}

variable "image" {
  description = "コンテナイメージ。Cloud Run サービスと同じものを使う"
  type        = string
}

variable "command" {
  description = "エントリポイントに渡すコマンド"
  type        = list(string)
  default     = ["php"]
}

variable "args" {
  description = <<-EOT
    コマンドの引数。
    entrypoint.sh は第1引数が supervisord 以外なら oneshot モードで動き、
    nginx の設定生成や config:cache を飛ばす (CDN_BASE_URL も要求しない)。
  EOT
  type        = list(string)
}

variable "env" {
  description = "平文で渡す環境変数"
  type        = map(string)
  default     = {}
}

variable "secret_env" {
  description = "Secret Manager から渡す環境変数。{ 環境変数名 = Secret ID }"
  type        = map(string)
  default     = {}
}

variable "cloudsql_connection_name" {
  description = "<project>:<region>:<instance>。ソケットのマウントに使う"
  type        = string
}

variable "max_retries" {
  description = <<-EOT
    失敗時の再試行回数。
    マイグレーションは途中まで流れている可能性があるため、
    自動で二度流さず 0 にして人が確認する。
  EOT
  type        = number
  default     = 0
}

variable "timeout" {
  description = "1 タスクの上限時間"
  type        = string
  default     = "600s"
}

variable "cpu" {
  description = "CPU"
  type        = string
  default     = "1"
}

variable "memory" {
  description = "メモリ"
  type        = string
  default     = "512Mi"
}
