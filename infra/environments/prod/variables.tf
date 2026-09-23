variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "既定のリージョン。Cloud Run / Cloud SQL / Artifact Registry を揃える"
  type        = string
  default     = "asia-northeast1"
}
