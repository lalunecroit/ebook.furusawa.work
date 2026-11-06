# 機密は入れない。DB パスワードや APP_KEY は Secret Manager に置く。
project_id = "my-project-book-509215"
region     = "asia-northeast1"

# CI/CD。どちらも公開情報で機密ではない
github_repository          = "lalunecroit/ebook.furusawa.work"
github_repository_id       = "1378122529"
github_repository_owner_id = "38752755"

# 本番の停止中。費用を止めるため、サイトは見えない状態にしている。
# DB はインスタンスごと消しており、データは db-export バケットのダンプにある。
# 復旧するときは次の行を消し、apply してからダンプを取り込む
min_instances          = 0
frontdoor_enabled      = false
db_deletion_protection = false
db_enabled             = false
