variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "name" {
  description = "バケット名。グローバルに一意"
  type        = string
}

variable "location" {
  description = "バケットのロケーション。LB 経由で配るのでリージョンで足りる"
  type        = string
  default     = "ASIA-NORTHEAST1"
}

variable "component" {
  description = "ラベル用の用途名 (frontend / cdn / docs)"
  type        = string
}

variable "default_ttl" {
  description = <<-EOT
    Cloud CDN がエッジに保持する秒数。
    オリジンが Cache-Control を返せばそちらが優先される。
  EOT
  type        = number
  default     = 3600
}

variable "client_ttl" {
  description = "ブラウザに持たせる秒数の上限"
  type        = number
  default     = 3600
}

variable "cors_origins" {
  description = <<-EOT
    CORS を許可するオリジン。
    <img src> で読むだけなら不要だが、将来 canvas や fetch で扱う場合に要る。
    空リストなら CORS 設定自体を作らない。
  EOT
  type        = list(string)
  default     = []
}

variable "force_destroy" {
  description = "中身があっても destroy できるようにするか。静的ファイルは再生成できるので true"
  type        = bool
  default     = true
}
