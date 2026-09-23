variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "Cloud Run のリージョン。Serverless NEG をここに作る"
  type        = string
}

variable "name" {
  description = "リソース名の接頭辞"
  type        = string
  default     = "ebook"
}

variable "domain" {
  description = "ルートドメイン (apex)。サブドメインはここから組み立てる"
  type        = string
}

variable "dns_zone_name" {
  description = <<-EOT
    レコードを追加する Cloud DNS ゾーンの名前。
    このゾーンは Terraform の管理外なので data で参照するだけにする。
    destroy してもゾーン自体は残り、ドメインが死なない。
  EOT
  type        = string
}

variable "frontend_backend_bucket_id" {
  description = "www. の向き先"
  type        = string
}

variable "cdn_backend_bucket_id" {
  description = "cdn. の向き先"
  type        = string
}

variable "docs_backend_bucket_id" {
  description = "docs. の向き先"
  type        = string
}

variable "api_service_name" {
  description = "api. の向き先になる Cloud Run サービス名"
  type        = string
}

variable "admin_service_name" {
  description = "admin. の向き先になる Cloud Run サービス名"
  type        = string
}

variable "dns_ttl" {
  description = <<-EOT
    A レコードの TTL (秒)。
    切り替えをやり直す可能性がある間は短くしておく。
  EOT
  type        = number
  default     = 300
}
