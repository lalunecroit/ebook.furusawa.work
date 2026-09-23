variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "リポジトリを置くリージョン。イメージを引く Cloud Run と揃える"
  type        = string
}

variable "repository_id" {
  description = "リポジトリ名。イメージは <region>-docker.pkg.dev/<project>/<repository_id>/<image> になる"
  type        = string
  default     = "app"
}

variable "keep_versions" {
  description = "タグ付きイメージを何世代残すか。ロールバック先として使う"
  type        = number
  default     = 10
}

variable "untagged_ttl" {
  description = <<-EOT
    タグの付いていないイメージを消すまでの保持期間 (秒)。
    build をやり直すと前のイメージからタグが外れ、そのまま残ると課金対象になる。
  EOT
  type        = string
  default     = "604800s" # 7日
}
