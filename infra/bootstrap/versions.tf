# bootstrap で使う Terraform とプロバイダのバージョン。
#
# バージョンを固定するのは、後から apply したときに
# 「プロバイダが上がっていて差分が出る」のを防ぐため。
# 上げるときは意図して上げ、plan の差分を読んでから通す。
terraform {
  required_version = ">= 1.16"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 7.9"
    }
  }
}

provider "google" {
  project = var.project_id
  region  = var.region
}
