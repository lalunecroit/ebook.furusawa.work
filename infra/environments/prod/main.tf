# 本番環境。modules/ の部品を組み合わせる。
#
# Cloud Run はイメージが無いと作れないので、まず registry だけを
#   terraform apply -target=module.registry
# で作り、docker push を済ませてから残りを流す。

module "registry" {
  source = "../../modules/registry"

  project_id = var.project_id
  region     = var.region
}

# B段。ここから固定費が発生する (Step.07 の 7 章: 約 1,800 円/月)。
module "database" {
  source = "../../modules/database"

  project_id = var.project_id
  region     = var.region
}
