# infra(gcp): Terraform で本番環境を作る — 設計方針と構築手順

ローカルの `compose.yaml`（frontend / docs / cdn / backend / db の 5 サービス）を GCP に載せます。
**Step.06 で backend に入れた管理画面（`/admin/*`）と、このドキュメント（`docs/`）も本番に出します。**
このドキュメントは **設計方針と、手を動かす順番** をまとめたものです。Terraform のコードはまだ書いていません。

> コード断片は**骨子**です。provider のバージョン差で引数名が変わることがあるため、
> 実際に書くときは `terraform validate` と provider ドキュメントで確認してください。

---

## 0. 全体像

### 構成

役割ごとにサブドメインを分けます。**5 つとも同じ 1 つの IP / 1 台の LB を指し**、LB がホスト名で振り分けます。

```
   Cloud DNS  ゾーン: ebook.furusawa.work
     www.ebook.furusawa.work    ─┐
     api.ebook.furusawa.work    ─┤
     admin.ebook.furusawa.work  ─┼─ A ─▶ 同一のグローバル静的IP
     cdn.ebook.furusawa.work    ─┤                      │
     docs.ebook.furusawa.work   ─┘                      │
                                                        │
  ブラウザ　 ─────────────────────────────────────────────┤
   ┌────────────────────────────────────────────────────▼─────────────┐
   │ 外部 Application LB + Cloud CDN                                 　│
   │ グローバル静的IP / マネージド証明書 1 枚                      　  　   │
   │ ホスト名で振り分ける (host_rule)                            　　     │
   └────┬────────────┬────────────┬────────────┬──────────────────┬───┘
    www.│        api.│      admin.│        cdn.│             docs.│
        │            │            │            │                  │
 ┌──────▼─────┐ ┌────▼───────┐ ┌──▼─────────┐ ┌▼───────────┐ ┌────▼───────┐
 │GCS frontend│ │ Cloud Run  │ │ Cloud Run  │ │ GCS (cdn)  │ │ GCS (docs) │
 │ HTML/CSS/JS│ │ ebook-api  │ │ ebook-admin│ │ ページ画像 　│ │ md/artifact│
 └────────────┘ │ /api/*     │ │ /admin/*   │ └────────────┘ └────────────┘
                └─────┬──────┘ └──┬──────┬──┘       ▲
                      │           │      └──────────┘
                      │           │   画像のアップロード
               ┌──────▼───────────▼──┐
               │ Cloud SQL (MySQL)   │  Unix socket /cloudsql/...
               └─────────────────────┘
 ┌────────────────────┐  ┌──────────────────┐
 │ Secret Manager     │  │ Artifact Registry│ ← docker push
 │ DB_PASSWORD/APP_KEY│  │ 1 イメージを共有  　│
 └────────────────────┘  └──────────────────┘
```

| ホスト | 向き先 | 中身 |
|---|---|---|
| `www.ebook.furusawa.work` | GCS (frontend) | ビューア本体 HTML/CSS/JS |
| `api.ebook.furusawa.work` | Cloud Run (`ebook-api`) | Laravel の公開 API（`/api/*`） |
| `admin.ebook.furusawa.work` | Cloud Run (`ebook-admin`) | Laravel の管理画面（`/admin/*`）。Step.06 で作ったもの |
| `cdn.ebook.furusawa.work` | GCS (cdn) | ページ画像 |
| `docs.ebook.furusawa.work` | GCS (docs) | このドキュメント（md / artifact） |
| `ebook.furusawa.work`（apex） | ↑ www へ 301 | 素で叩かれたとき用 |

本体を `www.` にせず **apex（`ebook.furusawa.work`）に置く手もあります。**
ラベルが 1 つ減って短く、`api.` / `cdn.` との並びも崩れません。その場合は上表の apex 行と www 行を入れ替えるだけで、以降の設計は変わりません。
どちらにしても、**使わなかった方からもう一方へ 301 を張る**のを忘れないようにします（同じ内容が 2 つの URL で見える状態を作らないため）。

### compose との対応

| compose サービス | GCP | 備考 |
|---|---|---|
| `frontend` (nginx :8080) | GCS バケット + backend bucket + Cloud CDN | nginx は不要になる。静的ファイルを置くだけ |
| `cdn` (nginx :8082) | GCS バケット + backend bucket + Cloud CDN | `Cache-Control` はオブジェクトのメタデータで付ける |
| `backend` (PHP :8000) | Cloud Run ×2（`ebook-api` / `ebook-admin`） | **同じイメージを 2 サービスとしてデプロイする**（4.8） |
| `backend` の `/admin/*` | Cloud Run (`ebook-admin`) | ローカルでは `backend-web` に同居。本番だけ分ける |
| `db` (MySQL 8.4) | Cloud SQL for MySQL | パスワードは Secret Manager |
| `docs` (nginx :8081) | GCS バケット + backend bucket + Cloud CDN | `autoindex` が無いので、一覧は `index.json` を生成して配る |

### サブドメインで分けると何が変わるか

**LB は 1 台のままです。** ホスト名での振り分けは URL マップの `host_rule` が担当するので、
IP も転送ルールも証明書も増えません。**つまり 7 章のコストは 1 円も変わりません。**

増えるのは DNS レコード（5 本）と、証明書に載せるドメイン名（5 つ）だけです。

一方で **CORS が戻ってきます。** パスで分けていたときは全部が同一オリジンでしたが、
`www.` から `api.` を `fetch` するのは**クロスオリジン**です。ホストが違えば同一オリジンではありません（プロトコル・ホスト・ポートの 3 つが一致して初めて同一オリジン）。
Step.02 で「Laravel 11 以降は既定で `/api/*` が通る」と書いた CORS 設定を、今度は**オリジンを絞った形で明示的に持つ**ことになります（6 章）。

| | パス分割（前案） | サブドメイン分割（本案） |
|---|---|---|
| LB / IP / 転送ルール | 1 | 1（変わらない） |
| 証明書 | 1 ドメイン | 1 枚に 5〜6 ドメイン |
| DNS レコード | 1 | 5〜6 |
| CORS | 不要 | **必要** |
| 役割の見分けやすさ | URL を読まないと分からない | ホスト名で一目 |
| 将来の切り離し | パスごと移すのは面倒 | **CNAME の向きを変えるだけ** |

最後の行が、この分割の一番の利点です。`cdn.` を将来 Cloudflare や別バケットに逃がしたくなったとき、
**DNS を書き換えるだけで済みます。** アプリ側は `CDN_BASE_URL` を見ているだけなので、コードは変わりません。

---

## 1. Terraform を書く前にやること

Terraform で作れないもの（＝ Terraform を動かすための土台）を先に用意します。**ここだけは手で叩きます。**

### 1.1 ツール

`gcloud` は導入済み。Terraform は未インストールなので入れます。

```bash
brew install terraform          # or: brew install opentofu
terraform version               # 1.16.x を想定
```

### 1.2 GCP プロジェクトを作る

```bash
# 現在の設定を確認（今は別プロジェクトが選択されている）
gcloud config list

# プロジェクト ID はグローバルに一意。末尾に数字を足す等で回避する
export PROJECT_ID=ebook-furusawa-prod
gcloud projects create "$PROJECT_ID" --name="ebook.furusawa.work prod"

# 請求先アカウントを紐付ける（これが無いと API 有効化で失敗する）
gcloud billing accounts list
gcloud billing projects link "$PROJECT_ID" --billing-account=XXXXXX-XXXXXX-XXXXXX

gcloud config set project "$PROJECT_ID"
```

> **プロジェクト自体を Terraform で作らないのはなぜか。**
> 作ることは可能ですが、その state をどこに置くかという問題が先に来ます（1.4 の鶏卵問題）。
> また `terraform destroy` の事故でプロジェクトごと消える状態は、個人の本番環境では避けたいところです。
> **プロジェクトは器、Terraform は中身**、と線を引きます。

### 1.3 認証（ADC）

ローカルから Terraform を動かすための認証です。サービスアカウントキー（JSON）は作りません。**漏洩したら終わりの長期鍵をディスクに置かないため**です。

```bash
gcloud auth application-default login
gcloud auth application-default set-quota-project "$PROJECT_ID"
```

CI（GitHub Actions）から動かす段階になったら、鍵なしの **Workload Identity Federation** を使います（9 章）。

### 1.4 tfstate の置き場所 — 鶏卵問題

state は GCS に置きたい。でも GCS バケットを Terraform で作るなら、そのバケットを作る apply の state はどこに置くのか？

**答え：bootstrap だけローカル state で apply し、あとから backend を設定して移す。**

```
1. bootstrap/ を local state で apply     → GCS バケットができる
2. bootstrap/backend.tf を書く            → backend "gcs" を指定
3. terraform init -migrate-state          → ローカルの state がバケットへ移動
4. 以降 prod/ は最初から backend "gcs"
```

バケットには必ず **バージョニング** を付けます。state の破損・誤 apply からの復旧手段がこれしかないためです。

### 1.5 API の有効化

これは Terraform でやります（`google_project_service`）。ただし **`terraform apply` 直後は有効化が伝播しておらず、続けて作るリソースが `API has not been used...` で落ちることがあります。**
`bootstrap` を先に apply して、API 有効化だけ済ませてから本体に進む構成にしておくと、この揺れを踏みません。

| API | 用途 |
|---|---|
| `compute.googleapis.com` | LB / IP / 証明書 |
| `run.googleapis.com` | Cloud Run |
| `sqladmin.googleapis.com` | Cloud SQL |
| `secretmanager.googleapis.com` | Secret Manager |
| `artifactregistry.googleapis.com` | コンテナレジストリ |
| `dns.googleapis.com` | Cloud DNS |
| `iam.googleapis.com` / `cloudresourcemanager.googleapis.com` | IAM / プロジェクト操作 |

---

## 2. ディレクトリ構成

```
infra/
├── bootstrap/                 # 土台。ほぼ触らない。単独で apply する
│   ├── main.tf                #   - tfstate 用 GCS バケット
│   ├── apis.tf                #   - google_project_service
│   └── backend.tf             #   - 初回 apply 後に追記して migrate
│
├── modules/                   # 再利用する部品。プロジェクト固有の値は入れない
│   ├── static_site/           #   GCS + backend bucket + CDN
│   ├── api_service/           #   Cloud Run + SA + IAM
│   ├── database/              #   Cloud SQL + DB + user + Secret
│   └── frontdoor/             #   LB + URL map + 証明書 + DNS
│
└── environments/
    └── prod/
        ├── main.tf            # modules を組み合わせる
        ├── variables.tf
        ├── terraform.tfvars   # project_id, region, domain（機密は入れない）
        ├── outputs.tf
        └── backend.tf
```

いまは prod 1 環境ですが、最初から `environments/prod/` に切っておきます。
**後から dev を足すときにディレクトリを掘り直す作業が発生しない**ためで、コストはディレクトリ 1 段だけです。

---

## 3. 作るリソース一覧

| # | リソース | Terraform |
|---|---|---|
| 1 | tfstate バケット | `google_storage_bucket` |
| 2 | API 有効化 ×8 | `google_project_service` |
| 3 | Artifact Registry (docker) | `google_artifact_registry_repository` |
| 4 | Cloud SQL インスタンス / DB / ユーザ | `google_sql_database_instance` / `_database` / `_user` |
| 5 | DB パスワード・APP_KEY | `google_secret_manager_secret` / `_version` |
| 6 | Cloud Run 用 SA + IAM | `google_service_account` / `google_project_iam_member` |
| 7 | Cloud Run サービス **×2**（`ebook-api` / `ebook-admin`） | `google_cloud_run_v2_service` |
| 8 | 静的バケット ×3 (frontend, cdn, docs) | `google_storage_bucket` + `_iam_member`(allUsers:objectViewer) |
| 9 | backend bucket (CDN 有効) ×3 | `google_compute_backend_bucket` |
| 10 | Serverless NEG + backend service **×2**（api / admin） | `google_compute_region_network_endpoint_group` / `google_compute_backend_service` |
| 11 | URL マップ / プロキシ / 転送ルール | `google_compute_url_map` / `_target_https_proxy` / `_global_forwarding_rule` |
| 12 | グローバル静的 IP | `google_compute_global_address` |
| 13 | マネージド証明書（5〜6 ドメインを 1 枚に） | `google_compute_managed_ssl_certificate` |
| 14 | HTTP→HTTPS リダイレクト | `google_compute_url_map` (redirect) + `_target_http_proxy` + 転送ルール |
| 15 | DNS ゾーン（`ebook.furusawa.work`） | `google_dns_managed_zone` |
| 16 | **A レコード ×5〜6**（www / api / admin / cdn / docs / apex） | `google_dns_record_set` |
| 17 | admin 用 SA + `cdn` バケットへの書き込み IAM | `google_service_account` / `google_storage_bucket_iam_member` |
| 18 | Cloud Armor ポリシー（admin の IP 制限） | `google_compute_security_policy` |

リージョンは `asia-northeast1`（東京）、LB と GCS の一部はグローバルです。

---

## 4. 設計上の判断と、そのコード骨子

### 4.1 provider は必ずピンする

```hcl
terraform {
  required_version = "~> 1.16"
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 8.0"        # 執筆時点の最新は 8.3.0 (2026-09-15)
    }
  }
}
```

google provider はメジャーバージョンで破壊的変更が入ります（8.0.0 では LB の `load_balancing_scheme` の既定値が
`EXTERNAL` → `EXTERNAL_MANAGED` に変わりました）。`~> 8.0` で上限を切っておかないと、ある日突然 plan が壊れます。

### 4.2 Cloud Run と Cloud SQL は Unix ソケットで繋ぐ

VPC も Serverless VPC Access コネクタも要りません。**ボリュームとしてソケットをマウントする**のが一番シンプルで、
コネクタの固定費（月約 1,500 円〜）も掛かりません。

```hcl
resource "google_cloud_run_v2_service" "api" {
  name     = "ebook-api"
  location = var.region
  ingress  = "INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER"   # LB 経由のみ受ける

  template {
    service_account = google_service_account.api.email

    # 使わない時間は 0 円
    scaling {
      min_instance_count = 0
    }

    volumes {
      name = "cloudsql"
      cloud_sql_instance {
        instances = [google_sql_database_instance.main.connection_name]
      }
    }

    containers {
      image = "${local.registry}/api:latest"

      # Cloud Run は $PORT を渡す。ここと listen するポートを一致させる
      ports {
        container_port = 8080
      }

      volume_mounts {
        name       = "cloudsql"
        mount_path = "/cloudsql"
      }

      env {
        name  = "DB_SOCKET"
        value = "/cloudsql/${google_sql_database_instance.main.connection_name}"
      }
      env {
        name = "DB_PASSWORD"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.db.secret_id
            version = "latest"
          }
        }
      }
    }
  }

  # イメージの更新は CI の仕事。Terraform に差分として見せない
  lifecycle {
    ignore_changes = [template[0].containers[0].image]
  }
}
```

`ingress` を LB 限定にすると `*.run.app` の URL が直接叩けなくなり、**CDN と WAF を迂回される経路が消えます。**

### 4.3 初回デプロイの鶏卵問題

Cloud Run サービスを作るにはイメージが要る。イメージを push するにはレジストリが要る。レジストリは Terraform で作る。

```
① bootstrap apply（API 有効化）
② Artifact Registry だけ apply
③ docker build && docker push（ここで初めてイメージが存在する）
④ 残り全部を apply
```

あるいは ④ を先に流したいなら、初回だけ `image` に公開のプレースホルダ（`us-docker.pkg.dev/cloudrun/container/hello`）を
指定して apply し、以降は 4.2 の `ignore_changes` で CI に任せる、という手もあります。

### 4.4 パスワードを state に書かない工夫

Terraform の state は**平文**です。`google_sql_user.password` に値を書けば state に残ります。
そこで **Terraform では乱数を生成して Secret Manager に入れるところまでをやり、アプリには Secret 参照だけを渡します。**

```hcl
resource "random_password" "db" {
  length  = 32
  special = false
}

resource "google_secret_manager_secret_version" "db" {
  secret      = google_secret_manager_secret.db.id
  secret_data = random_password.db.result
}
```

state に残る事実は変わらないので、**tfstate バケットは絶対に公開しない**（uniform bucket-level access + 非公開）のが前提です。
より厳密にやるなら書き込み専用引数（`secret_data_wo`）や、手動投入した Secret を `data` で参照する形にします。

### 4.5 静的サイトは「バケットは Terraform、中身は CI」

```hcl
resource "google_compute_backend_bucket" "cdn" {
  name        = "ebook-cdn"
  bucket_name = google_storage_bucket.cdn.name
  enable_cdn  = true
  cdn_policy {
    cache_mode  = "CACHE_ALL_STATIC"
    default_ttl = 3600
    client_ttl  = 31536000          # Step.02 の immutable 方針をそのまま踏襲
  }
}
```

**SVG や HTML そのものは Terraform で管理しません。** `google_storage_bucket_object` でファイルを 1 つずつ書くと、
ページが増えるたびに plan が膨らみ、アプリのデプロイとインフラの変更が同じ apply に混ざります。
配置は `gcloud storage rsync` に任せ、Terraform は**器だけ**を持ちます。

```bash
gcloud storage rsync -r cdn/public/      gs://ebook-cdn/      --cache-control="public, max-age=31536000, immutable"
gcloud storage rsync -r frontend/public/ gs://ebook-frontend/ --cache-control="public, max-age=300"
gcloud storage rsync -r docs/public/     gs://ebook-docs/     --cache-control="public, max-age=300"

# .md は既定で text/markdown になり「ソース」リンクがダウンロードになるので上書きする
gcloud storage rsync -r docs/public/md/  gs://ebook-docs/md/  --content-type=text/plain \
                                                              --cache-control="public, max-age=300"
```

### 4.6 URL マップ 1 枚でホスト名を振り分ける

`host_rule` を 5 本並べ、それぞれに `path_matcher` を 1 つずつ対応させます。
**パスでの分岐が要らなくなるので、`path_rule` は 1 つも書きません。**

```hcl
resource "google_compute_url_map" "main" {
  name = "ebook"

  # どの host_rule にも当たらなかったとき（IP 直打ち等）の行き先
  default_service = google_compute_backend_bucket.frontend.id

  host_rule {
    hosts        = ["www.ebook.furusawa.work"]
    path_matcher = "frontend"
  }
  host_rule {
    hosts        = ["api.ebook.furusawa.work"]
    path_matcher = "api"
  }
  host_rule {
    hosts        = ["admin.ebook.furusawa.work"]
    path_matcher = "admin"
  }
  host_rule {
    hosts        = ["cdn.ebook.furusawa.work"]
    path_matcher = "cdn"
  }
  host_rule {
    hosts        = ["docs.ebook.furusawa.work"]
    path_matcher = "docs"
  }
  host_rule {
    hosts        = ["ebook.furusawa.work"]     # apex は www へ寄せる
    path_matcher = "apex"
  }

  path_matcher {
    name            = "frontend"
    default_service = google_compute_backend_bucket.frontend.id
  }
  path_matcher {
    name            = "api"
    default_service = google_compute_backend_service.api.id
  }
  path_matcher {
    name            = "admin"
    default_service = google_compute_backend_service.admin.id
  }
  path_matcher {
    name            = "cdn"
    default_service = google_compute_backend_bucket.cdn.id
  }
  path_matcher {
    name            = "docs"
    default_service = google_compute_backend_bucket.docs.id
  }

  path_matcher {
    name = "apex"
    default_url_redirect {
      host_redirect          = "www.ebook.furusawa.work"
      https_redirect         = true
      redirect_response_code = "MOVED_PERMANENTLY_DEFAULT"   # 301
      strip_query            = false
    }
  }
}
```

**`default_service` は必ず置きます。** ホスト名が一致しないリクエスト（LB の IP を直に叩かれた場合など）は
どの `host_rule` にも当たらず、これが無いと 404 ではなく LB の設定エラーになります。

Serverless NEG を使う backend service には **ヘルスチェックを付けません**（サーバーレス NEG は対象外で、付けるとエラーになります）。
Cloud Run 側の可用性は Google が見ます。

### 4.7 証明書は 1 枚に 5〜6 ドメインを載せる

Google マネージド証明書は **複数ホスト名に対応しています。** サブドメインごとに証明書を分ける必要はありません。

```hcl
resource "google_compute_managed_ssl_certificate" "main" {
  name = "ebook-cert"
  managed {
    domains = [
      "ebook.furusawa.work",
      "www.ebook.furusawa.work",
      "api.ebook.furusawa.work",
      "admin.ebook.furusawa.work",
      "cdn.ebook.furusawa.work",
      "docs.ebook.furusawa.work",
    ]
  }
}
```

**ここが今回いちばん詰まりやすい箇所です。**

- マネージド証明書は **列挙した全ドメインの DNS が LB を指していること**を確認してから発行されます。
  **1 つでも指していないドメインがあると、証明書全体が `PROVISIONING` のまま止まります。**
  → DNS レコード 5〜6 本を**先に**作り、全部が伝播してから証明書の状態を見ます
- `domains` は変更すると**証明書リソースが作り直し**になります。後からサブドメインを足す予定があるなら、
  `create_before_destroy` を付けておくと切り替え中の断がなくなります

```hcl
  lifecycle {
    create_before_destroy = true
  }
```

**ワイルドカード（`*.ebook.furusawa.work`）を使いたい場合は、この古い方式では作れません。**
公式ドキュメントは *"Google-managed certificates using wildcards are only supported by Certificate Manager when using DNS authorization"* と明記しています。
**Certificate Manager**（`google_certificate_manager_dns_authorization` + `_certificate` + `_certificate_map`）に切り替えれば、
ワイルドカード 1 枚でサブドメインを何本でも増やせて、**DNS 認証なので LB に到達できなくても発行できます**（＝ DNS を先に切る必要がない）。

| | Compute Engine のマネージド証明書 | Certificate Manager |
|---|---|---|
| 記述量 | 少ない（リソース 1 つ） | 多い（3〜4 リソース） |
| ワイルドカード | **不可** | 可（DNS 認証時） |
| 発行条件 | 全ドメインが LB を指していること | DNS の TXT レコードだけ |
| ターゲットプロキシへの上限 | 15 枚 | 100 枚 |

**今回は 5〜6 ドメインで固定なので、記述量の少ない前者で十分です。**
サブドメインを気軽に増やしたくなったら Certificate Manager へ移す、という順番で問題ありません。

### 4.8 admin は「同じイメージ・別サービス」で分ける

`admin.` の向き先は Cloud Run をもう 1 つ立てます。**イメージは `ebook-api` とまったく同じもので、環境変数と SA だけを変えます。**

```hcl
resource "google_cloud_run_v2_service" "admin" {
  name     = "ebook-admin"
  location = var.region
  ingress  = "INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER"

  template {
    # cdn バケットへの書き込み権限を持つのはこちらだけ
    service_account = google_service_account.admin.email

    scaling {
      min_instance_count = 0
    }

    volumes {
      name = "cloudsql"
      cloud_sql_instance {
        instances = [google_sql_database_instance.main.connection_name]
      }
    }

    containers {
      image = "${local.registry}/api:latest"      # api と同じイメージを指す

      env {
        name  = "APP_URL"
        value = "https://admin.ebook.furusawa.work"
      }
      env {
        name  = "SESSION_DRIVER"
        value = "database"                        # 6 章参照
      }
      env {
        name  = "CDN_DISK"
        value = "gcs"
      }
    }
  }
}

# 書き込み権限は admin の SA にだけ付ける
resource "google_storage_bucket_iam_member" "admin_cdn_writer" {
  bucket = google_storage_bucket.cdn.name
  role   = "roles/storage.objectAdmin"
  member = "serviceAccount:${google_service_account.admin.email}"
}
```

**なぜ分けるのか。** ローカルでは php-fpm を 1 つに束ねたまま `/api/*` と `/admin/*` を同居させています（Step.06）。
本番で分けたくなる理由は**処理の重さではなく、権限とアクセス制御**です。

| | 1 サービスに同居 | 2 サービスに分ける（本案） |
|---|---|---|
| イメージ | 1 | 1（同じものを使う） |
| 固定費 | 変わらない | 変わらない（どちらも `min_instance_count = 0`） |
| GCS への書き込み権限 | 公開 API のプロセスも持つ | **admin 側の SA だけが持つ** |
| Cloud Armor / IAP | 公開 API ごと掛かる | **admin にだけ掛けられる** |
| 障害の影響 | 画像処理が公開 API を巻き込む | 分離される |
| Terraform の記述量 | 少ない | サービスと NEG が 1 組増える |

**3 行目が決め手です。** 管理画面は `cdn` バケットへ書き込みますが、公開 API は読むことすらしません（画像はブラウザが CDN から直接読む・Step.02）。
同じサービスに載せると、公開 API 側の穴から書き込み権限まで届いてしまいます。
サービスを分ければ SA が別になり、**公開 API に `storage.objectAdmin` を一切与えずに済みます。**

### admin へのアクセスを絞る

Step.06 で入れたセッション認証は**アプリ層**の防御です。その手前で落とせるなら、そのほうが安全でログも汚れません。

```hcl
resource "google_compute_security_policy" "admin" {
  name = "ebook-admin-allowlist"

  rule {
    action   = "allow"
    priority = 1000
    match {
      versioned_expr = "SRC_IPS_V1"
      config {
        src_ip_ranges = var.admin_allowed_cidrs   # 自宅・オフィスの固定 IP
      }
    }
  }

  # 既定ルール。priority は最大値で固定されている
  rule {
    action   = "deny(404)"
    priority = 2147483647
    match {
      versioned_expr = "SRC_IPS_V1"
      config {
        src_ip_ranges = ["*"]
      }
    }
  }
}
```

`deny(403)` ではなく **`deny(404)` にしておくと、管理画面の存在自体を隠せます。**
このポリシーを admin の backend service に `security_policy` で紐付けます。**`ebook-api` 側には付けません。**

固定 IP が取れない場合は **IAP（Identity-Aware Proxy）** で Google アカウント認証を前段に置く手もあります。
どちらも入れないなら、`admin.` はインターネットに開いた状態になります。**Step.06 のログイン画面だけが防御になる**ことを理解したうえで選んでください。

### ホスト名の取り違えはアプリ側でも塞ぐ

LB で振り分けても、`api.` に `/admin/login` を投げれば `ebook-api` のコンテナに届きます（同じイメージなのでルートは存在します）。
ルート定義をホストで縛っておくと、この経路が消えます。

```php
// bootstrap/app.php — Step.06 で足した admin ルートの登録に domain を足す
Route::domain(config('app.admin_host'))   // 本番: admin.ebook.furusawa.work
    ->middleware('web')
    ->prefix('admin')
    ->name('admin.')
    ->group(base_path('routes/admin.php'));
```

`config('app.admin_host')` をローカルでは `null` にしておけば、**compose での `localhost:8000/admin` は従来どおり動きます。**

---

## 5. 適用の順番

```bash
# ① 土台（ローカル state → GCS へ移行）
cd infra/bootstrap
terraform init && terraform apply
#   → backend.tf を書いて
terraform init -migrate-state

# ② 本体（イメージが要るリソースの手前まで）
cd ../environments/prod
terraform init
terraform apply -target=module.registry

# ③ イメージを push
gcloud auth configure-docker asia-northeast1-docker.pkg.dev
docker build -t asia-northeast1-docker.pkg.dev/$PROJECT_ID/app/api:v1 ./backend
docker push  asia-northeast1-docker.pkg.dev/$PROJECT_ID/app/api:v1

# ④ 残り全部
terraform apply

# ⑤ 静的ファイルを流し込む（docs は index.json を作ってから）
docker compose run --rm tools php tools/bin/generate-docs-index.php
gcloud storage rsync -r ../../../frontend/public/ gs://ebook-frontend/
gcloud storage rsync -r ../../../cdn/public/      gs://ebook-cdn/
gcloud storage rsync -r ../../../docs/public/     gs://ebook-docs/

# ⑥ ネームサーバをレジストラ側に設定（Cloud DNS のゾーンが返す NS 4 本）
terraform output name_servers
```

> **⑥ の後、証明書のプロビジョニングに 15〜60 分かかります。**
> マネージド証明書は「ドメインが本当にこの LB を指している」ことを DNS 越しに検証してから発行されます。
> **DNS より先に証明書は出ません。** `gcloud compute ssl-certificates describe` が `ACTIVE` になるまで待ちます。
> ここで待たずに何度も apply し直すと、原因が分からないまま時間を溶かします。
>
> **サブドメイン構成では、`www` / `api` / `admin` / `cdn` / `docs` / apex の A レコードが全部揃ってから待ちます。**
> 証明書はドメインごとに状態を持ち、**1 本でも `FAILED_NOT_VISIBLE` があると証明書全体が `ACTIVE` になりません。**
> どのドメインで止まっているかは次で確認できます。
>
> ```bash
> gcloud compute ssl-certificates describe ebook-cert --global \
>   --format="value(managed.domainStatus)"
> dig +short www.ebook.furusawa.work   # 全ホストが同じ IP を返すか
> ```

---

## 6. アプリ側に必要な変更

インフラだけ作っても今の `backend/` は本番で動きません。並行して必要になる作業です。

<!--
  1〜14 は対応済みのため表から削除した。
  1〜3 の実装: backend/Dockerfile.prod と backend/docker/prod/ 一式。
  4 の実装: .env.example と config/database.php のコメント。
  5 の実装: docker/prod/entrypoint.sh の APP_KEY チェック。
  6 の実装: docker/prod/entrypoint.sh の CDN_BASE_URL チェック。
  7 の実装: config/cors.php と tests/Feature/Api/CorsTest.php。
  8 の実装: compose.yaml と .env.example のコメント。
  9 の実装: bootstrap/app.php の trustProxies と tests/Feature/TrustProxiesTest.php。
  10 の実装: docker/prod/entrypoint.sh の実行モード分岐。
  11 の実装: Api/HealthController と routes/api.php、tests/Feature/Api/HealthTest.php。
  12 の実装: frontend/public/js/config.js。
  13 の実装: .env / .env.example / compose.yaml の SESSION_DRIVER。
  14 の実装: config/filesystems.php と AppServiceProvider::registerGcsDriver()。

  | 1 | 本番用 Dockerfile（nginx + php-fpm、または FrankenPHP） | `artisan serve` はシングルプロセスの開発用サーバ。Step.02 の積み残し |
  | 2 | `$PORT` を listen | Cloud Run はポートを環境変数で渡す（既定 8080） |
  | 3 | `composer install --no-dev --optimize-autoloader` をビルド時に | 起動のたびに composer が走る今の entrypoint は本番では不可 |
  | 4 | `DB_SOCKET` 対応の確認 | Laravel の `config/database.php` は `unix_socket` を既定で見る。TCP 用の `DB_HOST` は空にする |
  | 5 | `APP_KEY` を Secret Manager から | 起動のたびに生成すると暗号化済みデータが読めなくなる |
  | 6 | `CDN_BASE_URL=https://cdn.ebook.furusawa.work` | 画像の配り元。ホストが変わるだけで、組み立てロジックは Step.02 のまま |
  | 7 | **CORS をオリジン指定で明示する** | `www.` から `api.` への `fetch` はクロスオリジン。下記参照 |
  | 8 | `APP_URL=https://api.ebook.furusawa.work` | 生成される絶対 URL の基点 |
  | 9 | **`TrustProxies` を有効にする** | LB 配下では `X-Forwarded-Proto` を信頼しないと `url()` が `http://` を吐く |
  | 10 | マイグレーションをコンテナ起動時に走らせない | インスタンスが同時に複数立つと競合する。Cloud Run Jobs か手動実行に分離 |
  | 11 | `/api/health` の追加 | 監視と疎通確認用 |
  | 12 | フロントの `CONFIG.api.base` を `https://api.ebook.furusawa.work/api` に | Step.02 で `reader.js` に書いた API のベース URL |
  | 13 | **`SESSION_DRIVER=database`** | Step.02.5 で file にしたセッションは Cloud Run では保たない。下記参照 |
  | 14 | **`cdn` ディスクを GCS に差し替える** | Step.06 の画像アップロード先。ローカルディスクは Cloud Run に無い |

  4 について: 実機で確認したところ、DB_SOCKET が空でなければ Laravel は
  host/port を見ずにソケットで接続するため、表にあった「TCP 用の DB_HOST は
  空にする」は不要だった (MySqlConnector::getDsn)。DB_HOST に存在しないホストを
  入れたままでも接続できることを確認済み。

  5 について: Secret Manager の作成はインフラ側 (5 の Terraform リソース) の
  担当で、アプリ側は「APP_KEY を環境変数で受け取り、無ければ起動しない」だけ。
  供給元が Secret Manager か平文の環境変数かは、アプリからは区別できない。
  未設定でもコンテナは起動でき DB を読む API は 200 を返してしまい、暗号化を
  使う経路に来て初めて MissingAppKeyException で落ちることを実機で確認したため、
  entrypoint で先に止めている。

  6 について: 組み立てロジックは Step.02 のままで、変えたのはホスト名だけ
  (config('cdn.base_url') は rtrim してあるので末尾スラッシュ付きでも問題ない)。
  ただし config/cdn.php の既定値が開発用の localhost なので、本番で渡し忘れても
  コンテナは起動し API も 200 を返し、画像 URL だけが localhost になって
  ブラウザから取得できない状態になる。監視では気づけないため entrypoint で止めている。

  7 について: config/cors.php を新規に置き、allowed_origins を
  CORS_ALLOWED_ORIGINS (カンマ区切り) から読むようにした。paths は api/* だけで、
  管理画面は同一オリジンなので対象外。公開 API は参照のみなので
  allowed_methods は GET/HEAD/OPTIONS に絞ってある。
  なお php-cors は許可オリジンが1つだけだと、リクエスト元に関係なくその固定値を
  返す (CorsService::isSingleOriginAllowed)。値が一致しなければブラウザが弾くので
  安全だが、「許可外ならヘッダが無い」と思い込むと読み違えるのでテストに残した。

  8 について: 実測したところ、Web リクエスト中の route() はリクエストのホストを
  使い APP_URL を見ない。APP_URL が効くのは artisan やキュー・メールのように
  リクエストが無い文脈だけ。現状このアプリはメール・通知・キューをどれも使って
  おらず config('app.url') の直接参照も無いので、実行時の挙動には影響しない。
  それでも値を入れておくのは、今後キューやメールを足したときに
  http://localhost が混入するのを防ぐため。
  なお表は api. の値だけを書いているが、同じイメージを ebook-api と
  ebook-admin の2サービスで使うので、APP_URL はサービスごとに変える必要がある
  (admin 側は https://admin.ebook.furusawa.work)。

  9 について: 信頼するのは X-Forwarded-Proto と X-Forwarded-Port だけにした。
  X-Forwarded-For を信頼するとクライアントが自分の IP を名乗れてしまい、
  email|ip をキーにしているログインのレート制限 (LoginRequest::throttleKey) を
  IP を変えるだけで回避できる。Google の LB は受け取った X-Forwarded-For の
  右側に実 IP を足す形なので、左端を実 IP とみなす Symfony の既定とも噛み合わない。
  信頼しなければ $request->ip() はプロキシの IP になるが、これは今までと同じ挙動。
  X-Forwarded-Host も、Cloud Run も開発の nginx も元の Host をそのまま渡すので
  信頼していない (信頼すると生成 URL のホストを外から差し替えられる)。

  10 について: 起動時に流さないこと自体は最初から満たしていた (88f99ec の
  entrypoint はマイグレーションを実行しない)。空の DB に対して web モードで
  起動してもテーブルが作られないことを実機で確認済み。
  足りなかったのは「分離した実行経路」の方で、同じイメージに artisan を渡すと
  Web 用の準備が走り、画像URLを作らない migrate にまで CDN_BASE_URL を
  要求していた。entrypoint に実行モードの分岐を入れて解消した。
    web     ... CMD が supervisord のとき。nginx 設定生成とキャッシュ作成を行う
    oneshot ... それ以外。Web 用の準備を飛ばし、APP_KEY だけ要求する
  Cloud Run Jobs での流し方:
    gcloud run jobs create ebook-migrate --image <IMAGE> \
      --command php --args artisan,migrate,--force
    gcloud run jobs execute ebook-migrate

  11 について: 既定は DB に触らない浅い確認にした。LB のヘルスチェックが見るのは
  こちらで、DB まで見て 503 を返す作りにすると DB が一瞬詰まっただけで全
  インスタンスが不健全と判定されて同時に作り直され、障害が広がるため。
  DB まで確かめたいときは ?deep=1 を付ける (失敗時 503、監視や手動確認用)。
  組み込みの /up は HTML を返すので、機械で読む用に JSON のものを別に持つ。
  本番 nginx では health をアクセスログから外している。location に
  access_log off を書いても効かず (try_files で /index.php へ内部リダイレクト
  されるため)、map による条件付き access_log にしている。

  12 について: ベースURLは reader.js だけでなく library.js にも同じ値が
  書かれていた (「reader.js と同じ値」とコメント付きで重複していた) ので、
  frontend/public/js/config.js に集約した。
  フロントはビルドもテンプレート展開も無い静的ファイルなので、デプロイ時に値を
  差し込めない。そこで開いているページのホスト名から接続先を決める方式にした。
  環境ごとにファイルを差し替える方式にしなかったのは、本番用に書き換え忘れると
  公開サイトが localhost の API を見に行き、サーバ側は正常なのに画面だけ空に
  なるため。アップロードするファイルを環境で変えなければ、その事故が起きない。
  ステージング等を足すときは config.js の表に1行足すことになる。

  13 について: 本番イメージは .env を含まない (.dockerignore で除外) ため、
  config/session.php の既定値がそのまま効いて既に database になっていた。
  実際に本番イメージを SESSION_DRIVER 無しで2つ立てても、片方でログインして
  もう片方の保護ページが 200 を返すことを確認している。
  問題は「開発が file、本番が database」と食い違っていたこと。この状態では
  セッション絡みの不具合がローカルで再現しない。.env / .env.example / compose を
  database に揃えて明示した。
  file のときに実際に壊れることも再現済み:
    file     -> ログインしたインスタンス 200 / 別インスタンス 302 (ログイン画面へ)
    database -> どちらも 200
  なお sessions テーブルは Laravel 同梱のマイグレーションで作られるので、
  表のとおり追加のコードは不要だった。

  14 について: 本文に載せたとおり disk の定義だけの差し替えで済み、
  Storage::disk('cdn') を呼ぶコントローラは 1 行も変えていない。
  ただし本文に書いていない前提が2つあった。
  (a) Laravel が同梱するドライバは local / s3 / ftp / sftp だけで gcs は無い。
      league/flysystem-google-cloud-storage を入れたうえで Storage::extend で
      自分で繋ぐ必要がある (AppServiceProvider::registerGcsDriver)。
  (b) バケットは均一なバケットレベルのアクセス (allUsers:objectViewer を
      バケットに付ける = インフラ表の 8) を前提にしているので、オブジェクト単位の
      ACL が使えない。visibility => 'public' をそのまま渡すと書き込み時に 400 に
      なるため、UniformBucketLevelAccessVisibility を渡して無効化している。
  検証は fake-gcs-server に向けて実施し、put / exists / get / size / delete と、
  管理画面が実際に使う putFileAs が動くことを確認した。
  CDN_DISK 未設定なら従来どおり local のままであることも確認済み。

  残りの番号は振り直していない。コード内のコメントが「step07 の 5 / 10 / 17」と
  番号で参照しているため、振り直すと対応が取れなくなる。
-->

| # | 変更 | 理由 |
|---|---|---|
| 15 | `SESSION_SECURE_COOKIE=true` / `SESSION_DOMAIN=admin.ebook.furusawa.work` | Cookie を HTTPS と `admin.` に閉じる |
| 16 | `app.admin_host` の追加と `Route::domain()` | `api.` から `/admin/*` に届かせない（4.8） |
| 17 | アップロードの上限を 32MiB 未満に揃える | Cloud Run のリクエストサイズ上限。超えると Laravel まで届かず LB が 413 を返す |

### 管理画面（`admin.`）で追加になること

Step.06 の管理画面は**ローカルの前提を 2 つ持っています**。本番ではどちらも成立しません。

**1. セッションがファイル。** `storage/framework/sessions/` に書いています。
Cloud Run のインスタンスは**使い捨てで、同時に複数立ちます**。ログインしたインスタンスと次のリクエストを受けるインスタンスが違えば、**そのたびにログイン画面へ戻されます**。

```bash
SESSION_DRIVER=database
```

Laravel には `sessions` テーブルのマイグレーションが同梱されているので、**追加のコードは要りません。** Cloud SQL を既に使っているので、Redis を足す必要もありません。

**2. 画像の保存先がローカルディスク。** Step.06 は `Storage::disk('cdn')` 越しに書いており、そのディスクは `cdn/public` へのバインドマウントでした。
Cloud Run のファイルシステムは**書けてもインスタンスが消えれば失われます**。保存先を GCS バケットに変えます。

```php
// config/filesystems.php — disk の定義だけ差し替える
'cdn' => env('CDN_DISK') === 'gcs'
    ? [
        'driver'     => 'gcs',
        'bucket'     => env('CDN_BUCKET'),
        'visibility' => 'public',
        'throw'      => true,
    ]
    : [
        'driver'              => 'local',
        'root'                => env('CDN_ROOT', '/var/www/cdn'),
        'visibility'          => 'public',
        'directory_visibility'=> 'public',
        'throw'               => true,
    ],
```

**コントローラは 1 行も変わりません。** Step.06 で `Storage::disk('cdn')` に寄せてあるのは、まさにこの差し替えのためです。
`league/flysystem-google-cloud-storage` を入れ、認証は Cloud Run の SA（4.8）がそのまま使われます。

**キャッシュバスターはそのまま効きます。** Step.06 の `touch()` で `updated_at` が進み、API が返す `?v=` が変わるので、
Cloud CDN に古い画像が載っていても**新しい URL として取りに行きます**。手動のキャッシュ無効化は要りません。

**CORS は要りません。** 管理画面は Blade を `admin.` から返すので、画面もフォーム送信も同一オリジンです。
`config/cors.php` の `paths` は `['api/*']` のままにしておきます（`admin/*` を足すと、むしろ外部から叩ける口を開けることになります）。

### CORS（サブドメイン分割で戻ってくるもの）

Step.02 では `paths: ['api/*']` / `allowed_origins: ['*']` というフレームワーク既定のまま通していましたが、
オリジンが定まった今は**明示的に絞ります**。

```bash
php artisan config:publish cors
```

```php
// config/cors.php
'paths' => ['api/*'],
'allowed_origins' => [env('FRONTEND_ORIGIN', 'http://localhost:8080')],
'allowed_methods' => ['GET'],
'supports_credentials' => false,
```

`FRONTEND_ORIGIN=https://www.ebook.furusawa.work` を Cloud Run に渡します。
ローカルの compose では `http://localhost:8080` のままなので、**同じコードが両方で動きます。**

> `allowed_origins` に `*` を残したままでも動きますが、**将来 Cookie 認証（しおりのサーバ保存など）を入れる段階で詰みます。**
> `supports_credentials = true` と `allowed_origins = ['*']` は併用できない仕様だからです。今のうちに絞っておくほうが安全です。

**画像（`cdn.`）には CORS は要りません。** `<img src>` で読むだけなら CORS の対象外だからです。
ただし将来 `canvas` に描いて `toDataURL()` したり `fetch` で取得するなら必要になるので、
GCS バケット側に CORS 設定を入れておいても害はありません。

```hcl
resource "google_storage_bucket" "cdn" {
  # ...
  cors {
    origin          = ["https://www.ebook.furusawa.work"]
    method          = ["GET", "HEAD"]
    response_header = ["Content-Type"]
    max_age_seconds = 3600
  }
}
```

### TrustProxies

Cloud Run から見ると、リクエストは LB からの HTTP として届きます。
**そのままでは Laravel が「自分は http で動いている」と判断し、`url()` や `redirect()` が `http://` を返します。**

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');
})
```

Cloud Run は LB 以外からのアクセスを `ingress` で塞いである（4.2）ので、`at: '*'` でも実質的な危険はありません。

`php artisan config:cache` を使う場合、**キャッシュ後は `env()` が `null` を返します。**
環境変数は必ず `config/*.php` を経由して読む、という原則を守っていれば問題ありません（Step.02 の `config/cdn.php` はこの形になっています）。

---

## 7. コスト

公式価格ページと Cloud Billing カタログ API から取得した **asia-northeast1 / Tokyo の実価格**です（2026-09-21 時点、1 USD = 157.89 円で換算）。

> **サブドメインを 5 つに分けてもコストは変わりません。** ホスト名の振り分けは URL マップの中で完結し、
> IP も転送ルールも証明書も 1 つのままだからです。増えるのは DNS レコードだけで、これはゾーン料金に含まれます。
>
> **`ebook-admin` を別サービスにしても固定費は増えません。** Cloud Run は `min_instance_count = 0` なら
> リクエストが無い間は課金されず、管理画面のアクセス量では無料枠に収まります。
> ただし **Cloud Armor（4.8）はポリシー単位の固定費が掛かります。** 本書の価格表には含めていないので、
> 入れる前に現在の価格を確認してください。固定 IP からしか使わないなら、
> 代わりに IAP（追加費用なし）で塞ぐ選択肢もあります。

### 固定費（アクセスが 0 でも掛かるもの）

| 項目 | 単価 | 月額 | 円 |
|---|---|---|---|
| 外部 ALB のグローバル転送ルール | $0.025 / 時（**先頭 5 本まで同額**） | $18.25 | **約 2,880 円** |
| Cloud SQL Zonal Micro（`db-f1-micro`） | $0.014 / 時 | $10.22 | 約 1,610 円 |
| Cloud SQL ストレージ 10GiB（HDD） | $0.117 / GiB 月 | $1.17 | 約 185 円 |
| Cloud DNS ゾーン | $0.20 / ゾーン月 | $0.20 | 約 30 円 |
| **合計** | | **$29.84** | **約 4,700 円** |

ストレージを SSD（Standard storage $0.221/GiB 月）にすると +165 円です。

### 従量課金（この規模ではほぼ無視できる）

| 項目 | 単価 |
|---|---|
| LB のデータ処理（Tokyo） | 受信・送信とも $0.012 / GiB |
| Cloud CDN キャッシュ配信 | 数 GB では数十円 |
| Cloud Run（api / admin の 2 サービス） | どちらも min-instances=0 なら無料枠に収まる |
| GCS（frontend / cdn / docs の 3 バケット） / Artifact Registry | 数十円 |

### これ以上安くできるか

**結論：この構成のままでは下げられません。** 固定費の 95% を占める 2 つが、どちらも「使う以上その額」という性質のものだからです。

**外部 ALB（約 2,880 円）**

- $0.025/時は**転送ルール 5 本まで同じ値段**です。HTTP→HTTPS リダイレクト用の 2 本目を足しても増えません
- Tokyo の**リージョン**外部 ALB は $0.038/時（$27.74 = 約 4,380 円）で、**グローバルより高い**。切り替えても損します
- LB に無料枠はありません

#### 5 本を超えたら

6 本目以降は 1 本ずつ加算されます。

| | 先頭 5 本（合計） | 6 本目以降（1 本あたり） |
|---|---|---|
| グローバル転送ルール | $0.025/時（$18.25 月） | **$0.01/時（$7.30 月 ≒ 1,150 円）** |
| リージョン転送ルール（Tokyo） | $0.038/時（$27.74 月） | $0.015/時（$10.95 月 ≒ 1,730 円） |

10 本なら `$0.025 + 5 × $0.01 = $0.075/時` = $54.75/月です。

**枠の数え方に 2 つ注意点があります。**

1. **グローバルとリージョンは別枠**。グローバル 1 本 + リージョン 1 本 = それぞれの枠を 1 つずつ消費し、**$0.05/時**になります（$0.025 ではありません）
2. **プロジェクトごとに別枠**。別プロジェクトを作ると、そこで新しく $18.25/月 の下限が立ちます

この構成で使う本数は以下のとおりで、**当面 5 本には届きません。**

| 用途 | 本数 |
|---|---|
| HTTPS（443） | 1 |
| HTTP→HTTPS リダイレクト（80） | 1 |
| IPv6 に対応する場合（転送ルールは IP アドレス 1 つにつき 1 本） | +2 |

超えるのは「同じプロジェクトに dev を同居させて 8 本になる」ようなケースです。
なお公式ドキュメントも *"For most load balancing use cases, you need only one forwarding rule per load balancer"* としています。

**Cloud SQL（約 1,800 円）**

- ストレージの**最小は 10GiB** で、それ未満は選べません。つまり容量は既に下限です
- HDD にしても差は 165 円
- インスタンス料金は「稼働中（activation policy = ALWAYS）の秒数」に対する課金なので、**停止すれば止まります**（ストレージ代は継続）。ただし公開サイトでは常時停止はできません
- `db-g1-small` は $0.046/時（約 5,300 円）。上げる選択肢はあっても、下げる選択肢が `db-f1-micro` より下にありません

→ **Cloud SQL を使う限り約 1,800 円が下限**。

### 構成を変えた場合の比較

| 構成 | 月額 | Terraform で書く量 |
|---|---|---|
| **本書の構成**（LB + Cloud SQL） | 約 4,700 円 | 多い（LB・URL マップ・証明書・CDN・SQL） |
| LB + SQLite（Cloud SQL を止める） | 約 2,900 円 | やや減る |
| Firebase Hosting + Cloud Run + Cloud SQL | 約 1,800 円 | 大きく減る |
| Firebase Hosting + Cloud Run + SQLite | **約 0〜200 円** | ごくわずか |

**Firebase Hosting** は CDN・SSL 証明書・独自ドメインが込みで、無料枠が「保存 10GB / 転送 360MB 日」、超過分が $0.15/GB です。Cloud Run への rewrite も設定できるので、**LB の 2,880 円がまるごと消えます。**
代わりに URL マップの細かい制御、Cloud Armor、Cloud CDN の詳細設定は使えなくなり、**Terraform で書くものが Cloud Run と Artifact Registry だけになります。**

**SQLite をイメージに同梱する**案は、書籍データが読み取り専用である限り有効です。Eloquent もマイグレーションもそのまま動くので、Step.03 で書いたモデルとマイグレーションは 1 行も無駄になりません。しおりのサーバ保存のように**書き込みが必要になった時点で Cloud SQL に移す**、という順番にできます。

### 学習が目的なら「建てて壊す」

LB もCloud SQL も**秒課金**です。フル構成を建てて 3 日触って `terraform destroy` するなら、掛かるのは約 300 円です。
**常時公開する必要が出るまでは、これが一番安く、Terraform の練習としては最も効率が良いやり方です。**
（`terraform destroy` で消えないのは Cloud DNS ゾーンの NS 切り替えと、手で作ったプロジェクトだけです）

---

## 8. 落とし穴チェックリスト

- [ ] **プロジェクト ID はグローバル一意**。取られていたら作成が失敗する
- [ ] **請求先の紐付けを先に**。していないと API 有効化で止まる
- [ ] **API 有効化の伝播待ち**。bootstrap を分けて回避する
- [ ] **証明書は DNS が先**。NS 切り替え前に `ACTIVE` にはならない
- [ ] **証明書に載せた全ドメインの DNS を作ってから待つ**。`www` / `api` / `admin` / `cdn` / `docs` のうち **1 本でも欠けると証明書全体が `PROVISIONING` で止まります**。「なぜか SSL が有効にならない」の原因はたいていこれ
- [ ] **証明書の `domains` に `admin.` を入れ忘れない**。後から足すと証明書リソースが作り直しになる（4.7）
- [ ] **`default_service` を URL マップに置く**。ホスト名が一致しないリクエストの行き先が無いと設定エラーになる
- [ ] **CORS のオリジンを `*` のままにしない**。Cookie 認証を入れる段階で `supports_credentials` と併用できず詰む
- [ ] **`TrustProxies` を入れる**。入れないと LB 配下で `url()` が `http://` を返す
- [ ] **GCS を backend bucket で配るとき、ディレクトリ URL（`/` で終わる）が index.html を返すとは限りません。** バケットの website 設定が LB 経由で効くかは要検証です。効かない場合は URL マップの `url_rewrite` で `/` → `/index.html` に書き換えるか、フロント側を常に `/index.html` で参照する形に倒します。**apply 後に最初に確認する項目にしてください**
- [ ] **tfstate は平文**。バケットは非公開 + バージョニング + `prevent_destroy`
- [ ] **`google_project_service` は `disable_on_destroy = false`**。destroy 時に API ごと止めると他に波及する
- [ ] **Cloud Run の `ingress` を LB 限定に**。`*.run.app` が開いていると CDN を迂回される
- [ ] **リージョンを揃える**（`asia-northeast1`）。Cloud SQL と Cloud Run が別リージョンだと遅延と課金が増える
- [ ] **セッションを file のまま本番に出さない**。Cloud Run では**ログインし直しが無限に続く**症状になる。`SESSION_DRIVER=database`（6 章）
- [ ] **公開 API の SA に GCS の書き込み権限を与えない**。書き込むのは `ebook-admin` だけ（4.8）
- [ ] **`admin.` を無制限に公開しない**。Cloud Armor の IP 許可リストか IAP を前段に置く。置かないなら、Step.06 のログイン画面だけが防御になることを承知のうえで
- [ ] **`Route::domain()` でホストを縛る**。同じイメージなので、`api.` に `/admin/login` を投げれば届いてしまう
- [ ] **docs の `index.json` を生成してから rsync する**。忘れるとサイドバーが空のまま公開される。CI で「生成して差分が出たら落とす」のが確実
- [ ] **docs の `.md` に `--content-type=text/plain` を付ける**。既定では「ソース」リンクがダウンロードになる

---

## 9. 次の一手

1. **CI/CD** … GitHub Actions から Workload Identity Federation（鍵なし）で `docker push` → `gcloud run deploy` → `gcloud storage rsync`。Terraform 側は `google_iam_workload_identity_pool` を足すだけ
2. **`terraform plan` を PR に出す** … apply は手動承認。個人プロジェクトでも「差分を読んでから通す」習慣が効く
3. **dev 環境** … `environments/dev/` を足し、Cloud SQL は共有 or 省略
4. **監視** … Cloud Monitoring のアップタイムチェックとエラー率アラート。無料枠で足りる
5. **Cloud Armor** … LB があるのでレート制限を後付けできる。`admin.` の IP 許可リスト（4.8）もここ
6. **管理画面の権限分離** … 現状は `users` 全員が管理者（Step.06 の積み残し）。読者アカウントを作る前に権限列か別ガードが要る

---

## 現時点の未確認事項

- 上記コードは骨子であり、`terraform validate` を通していません（ローカルに terraform 未インストール）
- コストは公式価格ページと Cloud Billing カタログ API から取得した Tokyo リージョンの実価格です（2026-09-21 時点 / 1 USD = 157.89 円）。無料枠の消費状況や為替で変動します
- backend bucket の index.html 解決（8 章）は実機確認が必要です
- Cloud Armor の価格は本書の価格表に含めていません（7 章）。ポリシー単位の固定費が掛かるため、入れる前に確認が要ります
- GCS ディスク（6 章）は `league/flysystem-google-cloud-storage` を入れる前提で書いており、実際には導入していません
- backend bucket（frontend / cdn / docs）に IAP を付けられるかは未確認です。アクセスを絞るなら Cloud Armor を前提にしてください

🤖 Generated with [Claude Code](https://claude.com/claude-code)
