# tfstate を置く GCS バケット。
#
# 鶏卵問題: state は GCS に置きたいが、そのバケットを Terraform で作るなら
# その apply の state はどこに置くのか。
# → bootstrap だけローカル state で apply し、あとから backend.tf を書いて
#   `terraform init -migrate-state` で state をこのバケットへ移す。

locals {
  # 明示されなければ <プロジェクトID>-tfstate にする。
  # バケット名はグローバルに一意なので、プロジェクト ID を接頭辞にして衝突を避ける。
  state_bucket = coalesce(var.state_bucket_name, "${var.project_id}-tfstate")
}

resource "google_storage_bucket" "tfstate" {
  name     = local.state_bucket
  location = var.region

  # バージョニングは必須。state が壊れたときや誤 apply したときの
  # 復旧手段がこれしかない。
  versioning {
    enabled = true
  }

  # 古い版が無限に溜まるのを防ぐ。20 世代あれば遡るには十分で、
  # それ以上前の state に戻したい状況ならバックアップの話になる。
  lifecycle_rule {
    condition {
      num_newer_versions = 20
    }
    action {
      type = "Delete"
    }
  }

  # 中身があると destroy できない。state 入りのバケットを
  # うっかり消せてしまう方が危ないので false のままにする。
  force_destroy = false

  # オブジェクト単位の ACL を使わず、IAM だけで制御する。
  # 新規バケットの既定でもあるが、明示しておく。
  uniform_bucket_level_access = true

  # state には接続情報が入りうる。公開される経路を塞いでおく。
  public_access_prevention = "enforced"

  labels = {
    managed_by = "terraform"
    component  = "bootstrap"
  }

  # terraform destroy でここが消えると、すべての state を失う。
  # 消すときは意図してこのブロックを外す。
  lifecycle {
    prevent_destroy = true
  }
}
