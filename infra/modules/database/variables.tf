variable "project_id" {
  description = "GCP プロジェクト ID"
  type        = string
}

variable "region" {
  description = "インスタンスを置くリージョン。Cloud Run と揃える"
  type        = string
}

variable "instance_name" {
  description = <<-EOT
    Cloud SQL インスタンス名。
    削除後も同じ名前を数日間再利用できないため、作り直す予定があるなら接尾辞を付ける。
  EOT
  type        = string
  default     = "ebook-db"
}

variable "database_version" {
  description = <<-EOT
    MySQL のバージョン。
    MYSQL_8_4 を指定すると API がエディションを ENTERPRISE_PLUS に寄せ、
    db-f1-micro が使えなくなる (最小が db-perf-optimized-N-2 / 16GiB)。
    ローカルの compose は 8.4 だが、このアプリで効く差分は無いので 8.0 にする。
  EOT
  type        = string
  default     = "MYSQL_8_0"
}

variable "edition" {
  description = <<-EOT
    ENTERPRISE か ENTERPRISE_PLUS。
    明示しないと API がバージョンに応じて勝手に選ぶので、必ず指定する。
    共有コアの安いティア (db-f1-micro / db-g1-small) は ENTERPRISE 専用。
  EOT
  type        = string
  default     = "ENTERPRISE"
}

variable "tier" {
  description = "マシンタイプ。ENTERPRISE エディションの db-f1-micro がこの構成の下限"
  type        = string
  default     = "db-f1-micro"
}

variable "disk_size_gb" {
  description = "ストレージ。10GiB が最小で、それ未満は選べない"
  type        = number
  default     = 10
}

variable "disk_type" {
  description = "PD_HDD か PD_SSD。この規模では HDD で足りる"
  type        = string
  default     = "PD_HDD"
}

variable "database_name" {
  description = "アプリが使うデータベース名"
  type        = string
  default     = "ebooks"
}

variable "database_user" {
  description = "アプリが使う DB ユーザ名"
  type        = string
  default     = "ebooks"
}

variable "deletion_protection" {
  description = <<-EOT
    誤って destroy するのを防ぐ。
    学習のために「建てて壊す」運用をするなら false にする。
  EOT
  type        = bool
  default     = true
}
