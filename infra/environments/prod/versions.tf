# bootstrap と同じ版に固定する。
# プロバイダが上がると plan に意図しない差分が出るため、上げるときは意図して上げる。
terraform {
  required_version = ">= 1.16"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 7.9"
    }

    # DB パスワードの生成に使う (modules/database)
    random = {
      source  = "hashicorp/random"
      version = "~> 3.7"
    }
  }
}

provider "google" {
  project = var.project_id
  region  = var.region
}
