# GitHub Actions から GCP に鍵なしで入るための仕組み (Workload Identity Federation)。
#
# サービスアカウントの JSON 鍵を GitHub の Secrets に置く方式は、鍵が漏れた時点で
# 誰でも GCP を操作できる。WIF では鍵がそもそも存在しない。
# GitHub が実行ごとに発行する OIDC トークン (「このリポジトリのこのブランチで
# 動いている」という署名付きの証明) を GCP が検証し、短命のトークンに交換する。
#
#   GitHub Actions ─ OIDC トークン ─▶ STS (検証・交換) ─▶ SA になりすます ─▶ GCP

locals {
  pool_name = google_iam_workload_identity_pool.github.name

  # CD 用。main ブランチへの push からの実行だけを受け付ける。
  #
  # subject (assertion.sub) で縛るのは、ref だけで縛ると pull_request_target の
  # ような「PR 起点なのに ref が main になる」イベントでも通ってしまうため。
  # push の subject は repo:<owner>/<name>:ref:refs/heads/<branch> になる。
  deployer_principal = "principal://iam.googleapis.com/${local.pool_name}/subject/repo:${var.repository}:ref:refs/heads/${var.deploy_branch}"

  # CI 用。このリポジトリからの実行なら、どのブランチ・どの PR でも受け付ける。
  # 読み取り専用なので広くてよい。フォークからの PR には GitHub が OIDC トークンを発行しない。
  planner_principal = "principalSet://iam.googleapis.com/${local.pool_name}/attribute.repository_id/${var.repository_id}"
}

# --------------------------------------------------------------- 受け口
# destroy してもプールは 30 日間ソフトデリートされ、同じ ID では作り直せない。
resource "google_iam_workload_identity_pool" "github" {
  project                   = var.project_id
  workload_identity_pool_id = "github"
  display_name              = "GitHub Actions"
  description               = "${var.repository} の GitHub Actions からの実行を受け付ける"
}

resource "google_iam_workload_identity_pool_provider" "github" {
  project                            = var.project_id
  workload_identity_pool_id          = google_iam_workload_identity_pool.github.workload_identity_pool_id
  workload_identity_pool_provider_id = "github-actions"
  display_name                       = "GitHub Actions OIDC"

  oidc {
    issuer_uri = "https://token.actions.githubusercontent.com"
  }

  # GitHub のトークンに入っている値のうち、判定に使うものを取り出しておく
  attribute_mapping = {
    "google.subject"          = "assertion.sub"
    "attribute.repository"    = "assertion.repository"
    "attribute.repository_id" = "assertion.repository_id"
    "attribute.ref"           = "assertion.ref"
  }

  # これが無いと、GitHub 上の「どのリポジトリ」からでもトークン交換が通ってしまう。
  # 名前ではなく数値 ID で縛る (variables.tf の repository_id を参照)。
  attribute_condition = "assertion.repository_id == '${var.repository_id}'"
}

# --------------------------------------------------------------- SA
resource "google_service_account" "deployer" {
  project      = var.project_id
  account_id   = "github-deployer"
  display_name = "GitHub Actions (CD)"
  description  = "main へのマージ時にイメージの push と Cloud Run の更新を行う"
}

resource "google_service_account" "planner" {
  project      = var.project_id
  account_id   = "github-planner"
  display_name = "GitHub Actions (CI)"
  description  = "PR 時に terraform plan を実行する。読み取り専用"
}

# GitHub からの実行がこの SA になりすませるようにする
resource "google_service_account_iam_member" "deployer_wif" {
  service_account_id = google_service_account.deployer.name
  role               = "roles/iam.workloadIdentityUser"
  member             = local.deployer_principal
}

resource "google_service_account_iam_member" "planner_wif" {
  service_account_id = google_service_account.planner.name
  role               = "roles/iam.workloadIdentityUser"
  member             = local.planner_principal
}
