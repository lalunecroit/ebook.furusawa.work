variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "既定のリージョン。tfstate バケットの置き場所にも使う"
  type        = string
  default     = "asia-northeast1"
}

variable "state_bucket_name" {
  description = <<-EOT
    tfstate を置く GCS バケット名。
    バケット名はグローバルに一意なので、プロジェクト ID を接頭辞にして衝突を避ける。
  EOT
  type        = string
  default     = null
}
