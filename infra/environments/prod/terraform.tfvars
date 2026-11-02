# 機密は入れない。DB パスワードや APP_KEY は Secret Manager に置く。
project_id = "my-project-book-509215"
region     = "asia-northeast1"

# CI/CD。どちらも公開情報で機密ではない
github_repository          = "lalunecroit/ebook.furusawa.work"
github_repository_id       = "1378122529"
github_repository_owner_id = "38752755"

# 本番の停止中。費用を止めるため、サイトは見えない状態にしている。
# 復旧するときは次の 3 つを既定値に戻す (下から順に行を消せばよい)
min_instances        = 0
db_activation_policy = "NEVER"
frontdoor_enabled    = false
