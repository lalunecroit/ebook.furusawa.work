# CD 用 SA の権限。
#
# プロジェクト全体の編集者 (roles/editor) を渡せば楽だが、漏れたときに何でもできてしまう。
# デプロイで実際に触るリソースにだけ、必要な役割を付ける。

locals {
  deployer = "serviceAccount:${google_service_account.deployer.email}"
}

# イメージの push
resource "google_artifact_registry_repository_iam_member" "deployer_push" {
  project    = var.project_id
  location   = var.region
  repository = var.artifact_registry_repository
  role       = "roles/artifactregistry.writer"
  member     = local.deployer
}

# Cloud Run のイメージ差し替え (gcloud run services update --image)
resource "google_cloud_run_v2_service_iam_member" "deployer" {
  for_each = toset(var.cloud_run_services)

  project  = var.project_id
  location = var.region
  name     = each.value
  role     = "roles/run.developer"
  member   = local.deployer
}

# Job のイメージ差し替えと実行 (gcloud run jobs update / execute)
resource "google_cloud_run_v2_job_iam_member" "deployer" {
  for_each = toset(var.cloud_run_jobs)

  project  = var.project_id
  location = var.region
  name     = each.value
  role     = "roles/run.developer"
  member   = local.deployer
}

# 新しいリビジョンを「実行時の SA として」動かす権限。
# これが無いと、イメージの差し替え自体が権限エラーになる。
resource "google_service_account_iam_member" "deployer_act_as" {
  for_each = toset(var.runtime_service_accounts)

  service_account_id = "projects/${var.project_id}/serviceAccounts/${each.value}"
  role               = "roles/iam.serviceAccountUser"
  member             = local.deployer
}

# 静的ファイルの rsync と、.md の Content-Type の付け直し
resource "google_storage_bucket_iam_member" "deployer_upload" {
  for_each = toset(var.deploy_buckets)

  bucket = each.value
  role   = "roles/storage.objectAdmin"
  member = local.deployer
}

# gcloud storage rsync は、転送の前にバケット自体のメタデータを読む (storage.buckets.get)。
# objectAdmin はオブジェクトの操作だけで、これを含まない。
# legacyBucketReader は buckets.get とオブジェクトの一覧だけの役割で、
# バケットの設定や IAM は変えられない。storage.admin まで上げずに済む。
resource "google_storage_bucket_iam_member" "deployer_bucket_read" {
  for_each = toset(var.deploy_buckets)

  bucket = each.value
  role   = "roles/storage.legacyBucketReader"
  member = local.deployer
}

# Cloud CDN のキャッシュ無効化。
#
# 既成の役割だと roles/compute.loadBalancerAdmin まで上げる必要があり、
# LB の設定そのものを書き換えられてしまう。無効化に要る権限だけのカスタムロールを作る。
# 削除後 37 日間は同じ role_id を使えない点に注意。
resource "google_project_iam_custom_role" "cdn_invalidator" {
  project     = var.project_id
  role_id     = "cdnCacheInvalidator"
  title       = "Cloud CDN キャッシュ無効化"
  description = "URL マップを読み、キャッシュを無効化するだけ"

  permissions = [
    "compute.urlMaps.get",
    "compute.urlMaps.invalidateCache",
    "compute.globalOperations.get", # 無効化の完了待ち
  ]
}

resource "google_project_iam_member" "deployer_cdn" {
  project = var.project_id
  role    = google_project_iam_custom_role.cdn_invalidator.id
  member  = local.deployer
}
