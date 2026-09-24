# CI 用 SA の権限。terraform plan を流すための読み取りだけ。
#
# plan は「実際のリソースの状態」と「state」と「*.tf」を突き合わせるので、
# 管理しているリソースをすべて読める必要がある。
#
# 注意: state には DB パスワードが平文で入っている (step08 の 5.4)。
# この SA はそれを読めるので、このリポジトリに push できる人は間接的に
# DB パスワードを読めることになる。個人のリポジトリなので許容している。

locals {
  planner = "serviceAccount:${google_service_account.planner.email}"
}

# リソースの読み取り
resource "google_project_iam_member" "planner_viewer" {
  project = var.project_id
  role    = "roles/viewer"
  member  = local.planner
}

# IAM ポリシーの読み取り。viewer だけでは読めないものがあり、
# *_iam_member を管理している以上 plan で必ず読みにいく。
resource "google_project_iam_member" "planner_iam_reader" {
  project = var.project_id
  role    = "roles/iam.securityReviewer"
  member  = local.planner
}

# state の読み取り。
# 書き込みは渡さないので、plan は -lock=false で流す (ロックファイルを作れないため)。
# 読むだけの plan がロックを取らなくても、state が壊れることはない。
resource "google_storage_bucket_iam_member" "planner_state" {
  bucket = var.state_bucket
  role   = "roles/storage.objectViewer"
  member = local.planner
}

# Terraform が値を管理している Secret は、refresh で実際の値を読みにいく
resource "google_secret_manager_secret_iam_member" "planner_secret" {
  for_each = toset(var.plan_readable_secrets)

  project   = var.project_id
  secret_id = each.value
  role      = "roles/secretmanager.secretAccessor"
  member    = local.planner
}
