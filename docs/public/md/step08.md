# infra(gcp): Terraform で本番環境を作る — 実装

Step.07 で **設計** と **準備** を終えました。ここから実際に Terraform を書きます。

「なぜこの構成か」の根拠は Step.07 側にあります（2 章のディレクトリ構成、3 章のリソース一覧、
4 章の設計判断、5 章の適用順）。このドキュメントは **何を書いて、どの順で流したか** の記録です。

| # | コミット | 内容 |
|---|---|---|
| — | （着手前） | |

---

## 0. このステップの範囲

Step.07 との境界をはっきりさせておきます。

| | Step.07 | Step.08（このステップ） |
|---|---|---|
| 設計 | ✅ 済み | 参照するだけ |
| 手作業の準備 | ✅ 済み | — |
| アプリ側 17 項目 | ✅ 済み | — |
| Terraform のコード | 骨子のみ | **書く** |
| GCP 上のリソース | 無し | **作る** |
| 静的ファイルの配置 | 無し | **流し込む** |

---

## 1. 前提（Step.07 で済ませたこと）

```
terraform        v1.16.3
プロジェクト       my-project-book-509215
課金             billingEnabled: true
認証             ADC（quota project 設定済み）
権限             roles/owner
```

有効な API の状況も確認済みです。bootstrap が使う 2 つが既に有効なので、
**API を手で叩く必要はありません**。

| API | 状態 |
|---|---|
| `storage.googleapis.com` | 有効（tfstate バケット作成に必要） |
| `serviceusage.googleapis.com` | 有効（他の API を有効化するのに必要） |
| `dns.googleapis.com` | 有効 |
| `compute` / `run` / `sqladmin` / `secretmanager` / `artifactregistry` / `iam` / `cloudresourcemanager` | 未有効（Terraform で有効化） |

> **プロジェクト ID について。** Step.07 の本文は `ebook-furusawa-prod` という例示のままです。
> 実際は `my-project-book-509215` なので、`terraform.tfvars` にはこちらを入れます。

---

## 2. bootstrap — 鶏卵問題の解き方

state は GCS に置きたい。でもそのバケットを Terraform で作るなら、その apply の state はどこに置くのか。

```
1. bootstrap/ を local state で apply     → GCS バケット + API 有効化
2. bootstrap/backend.tf を書く            → backend "gcs" を指定
3. terraform init -migrate-state          → ローカルの state がバケットへ移動
4. 以降 environments/prod/ は最初から backend "gcs"
```

bootstrap に入れるのは 2 つだけです。

- **tfstate 用の GCS バケット** — **バージョニング必須**。state の破損・誤 apply からの復旧手段がこれしかない
- **`google_project_service`** — 上の表の「未有効」7 つ

API 有効化を bootstrap 側に置くのは、`terraform apply` 直後は有効化が伝播しておらず、
続けて作るリソースが `API has not been used...` で落ちることがあるためです。
先に bootstrap を流しておけば、この揺れを踏みません。

---

## 3. 書く順番

Step.07 の 5 章がそのまま手順になります。イメージを push する前後で apply が 2 回に分かれる点が肝です。

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

# ③ イメージを push（パスがリポジトリルート基準なので戻る）
cd ../../..
gcloud auth configure-docker asia-northeast1-docker.pkg.dev
docker build --platform linux/amd64 \
  -f backend/Dockerfile.prod \
  -t asia-northeast1-docker.pkg.dev/my-project-book-509215/app/api:v1 \
  ./backend
docker push asia-northeast1-docker.pkg.dev/my-project-book-509215/app/api:v1

# ④ 残り全部
cd infra/environments/prod
terraform apply

# ⑤ 静的ファイルを流し込む（3.4）
# ⑥ ネームサーバをレジストラ側に設定（5.1 の通り不要）
```

**`cd` の行き来に注意します。** ①②④は `infra/` 配下、③⑤はリポジトリルートが基準です。
`terraform` 側を `terraform -chdir=infra/environments/prod apply` の形に統一すれば
ずっとルートに居られますが、ここでは公式ドキュメントに合わせて `cd` する書き方にしています。

### 3.1 ③ で踏みやすいところ

**`--platform linux/amd64` は必須です。** 手元は Apple Silicon（`darwin_arm64`）なので、
付けないと arm64 のイメージができます。**Cloud Run が動かせるのは amd64 だけ**で、
push も deploy も成功したように見えたうえで、**起動時に `exec format error` で落ちます**。
エミュレーション越しのビルドになるため時間は掛かりますが、それが正常です。

**イメージ名に `app` を二重に書かないこと。** `terraform output registry_url` が返すのは
`asia-northeast1-docker.pkg.dev/my-project-book-509215/app` で、**リポジトリ名まで含んでいます**。
イメージ名はこれに `/api:v1` を足した形です。

**ビルドコンテキストは `./backend`。** `Dockerfile.prod` の `COPY docker/prod/...` や
`COPY composer.json` はすべて `backend/` からの相対パスなので、リポジトリルートから叩くなら
`-f` でファイルを指し、コンテキストは `./backend` を渡します。

**タグは `v1` で進めます。** 5 章の未決事項に挙げた「`v1` 固定かコミットハッシュか」は、
CI を入れる段階で決めれば足ります。それまで手で push するので `v1` の上書きで困りません。

### 3.2 ④ は B / C / D に分けて流す

④「残り全部」を一度に書くと plan が大きくなって読めません。
**課金が始まる地点**と**待ち時間が出る地点**で区切ります。

| 段 | モジュール | 作るもの | 月額 | 所要 |
|---|---|---|---|---|
| **B** | `database` | Cloud SQL / DB / ユーザ / Secret ×2 | 約 1,800 円 | 10〜15 分 |
| **C** | `static_site` ×3 + `api_service` ×2 | GCS 3 本 + Cloud Run（api / admin）+ SA + IAM | min=1 のぶん課金 | 数分 |
| **D** | `frontdoor` | LB + 証明書 + DNS レコード | 約 2,900 円 | **15〜60 分** |

D の所要時間はマネージド証明書のプロビジョニング待ちです。
**DNS レコードが全部揃ってからでないと `ACTIVE` になりません**（Step.07 の 8 章）。
ここだけ独立させておくと、待っている間に他を触らずに済みます。

**GCS バケットが C にあるのは、`admin` がそれに依存するからです。** `cdn` バケットへの
`storage.objectAdmin` を `api_service` モジュールの中で付けているため、
バケットが無いと `module.admin` を作れません。

区切りの基準は「モジュールの種類」ではなく**課金が始まる地点と待ち時間が出る地点**です。
バケットは数十円・数秒なので、D の課金ゲート（LB の約 2,880 円）は動きません。
**C = アプリが動く状態が揃う / D = インターネットに公開する**、という線引きになります。

**区切りは `-target` ではなく、コードを書く順番で作ります。** その段のモジュールを
書いた時点で apply すれば、`*.tf` にまだ無いものは作られません。毎回素の
`terraform apply` で足ります。

```bash
cd infra/environments/prod
terraform apply     # B（database を書いた時点）
terraform apply     # C（static_site と api_service を書き足した時点）
terraform apply     # D（frontdoor を書き足した時点）
```

`-target` は使いません。本来デバッグ用の機能で、**指定したリソース以外の出力値が
state に書かれない**という副作用があります。

---

### 3.3 B 段の手順

```bash
cd infra/environments/prod

# 1. 何ができるか読む
terraform plan
#   → Plan: 7 to add, 0 to change, 0 to destroy.

# 2. 作る（Cloud SQL の作成に 10〜15 分かかる）
terraform apply

# 3. 出力を確認
terraform output
#   db_connection_name    = "my-project-book-509215:asia-northeast1:ebook-db"
#   db_password_secret_id = "ebook-db-password"
#   app_key_secret_id     = "ebook-app-key"

# 4. APP_KEY を投入する（箱だけ作ってあるので値を入れる / 5.4）
cd ../../..
docker compose exec backend php artisan key:generate --show | \
  gcloud secrets versions add ebook-app-key --data-file=-

# 5. 入ったか確認（1 件あれば OK）
gcloud secrets versions list ebook-app-key
```

> **`APP_KEY` の投入を飛ばすと C 段で止まります。** `APP_KEY` は箱（Secret）だけ Terraform が作り、
> 値は手で入れる約束になっています（5.4）。空のまま Cloud Run を作ると、
> Step.07 で入れた起動時ガード（`docker/prod/entrypoint.sh`）が
> **`APP_KEY` 未設定を検知してコンテナを起動させません**。
> デプロイは成功したのにコンテナが上がらない、という分かりにくい形で出ます。

最後に DB へ繋がることを確認します（4.1 の phpMyAdmin が手軽です）。
**テーブルが 1 つも無いのが正しい状態**で、マイグレーションは C 段のあとに流します（4.2）。

#### B 段の完了条件

| | 確認方法 | 期待 |
|---|---|---|
| Cloud SQL | `gcloud sql instances list` | `ebook-db` が `RUNNABLE` |
| DB とユーザ | 4.1 の phpMyAdmin で接続 | `ebooks` が見え、テーブルは 0 件 |
| DB パスワード | `gcloud secrets versions list ebook-db-password` | 1 件 |
| **`APP_KEY`** | `gcloud secrets versions list ebook-app-key` | **1 件**（0 件なら上の投入を実行） |
| Terraform | `terraform plan` | `No changes.` |

#### B 段で踏んだエラー：`Invalid Tier (db-f1-micro) for (ENTERPRISE_PLUS) Edition`

最初の apply はここで落ちました。

```
Error: Error, failed to create instance ebook-db: googleapi: Error 400:
Invalid request: Invalid Tier (db-f1-micro) for (ENTERPRISE_PLUS) Edition.
Use a predefined Tier like db-perf-optimized-N-* instead.
```

`edition` を書いていなかったため、**API が `MYSQL_8_4` を見てエディションを
`ENTERPRISE_PLUS` に寄せた**のが原因です。共有コアの安いティアは ENTERPRISE 専用で、
ENTERPRISE_PLUS の最小は `db-perf-optimized-N-2` です。

| | RAM | エディション |
|---|---|---|
| `db-f1-micro` | 614 MiB | ENTERPRISE |
| `db-g1-small` | 1.7 GiB | ENTERPRISE |
| `db-perf-optimized-N-2` | **16 GiB** | ENTERPRISE_PLUS |

**月額が一桁変わる**ので、`db-f1-micro` を使うには ENTERPRISE でなければなりません。
そこで 2 つ直しました。

```hcl
database_version = "MYSQL_8_0"   # 8.4 だと ENTERPRISE_PLUS に寄る
settings {
  edition = "ENTERPRISE"          # 省略せず必ず書く
  tier    = "db-f1-micro"
}
```

**`edition` を省略しないことが教訓です。** 既定値が「バージョンに応じて変わる」タイプの引数は、
書かないと環境によって結果が変わります。

ローカルの compose は `mysql:8.4` のままなので**本番と 1 マイナーバージョンずれます**が、
このアプリが使う機能に 8.0 と 8.4 の差はありません。揃えたい場合は compose を `mysql:8.0` にして
`docker compose down -v` → `migrate --seed` で作り直せます（シーダーがあるので手間は小さい）。

> **失敗しても中途半端なリソースは残りませんでした。** Secret 2 つと `random_password` は
> 作成済みとして state に入り、Cloud SQL だけが未作成です。修正後の plan は
> `3 to add` になり、**作成済みのものは作り直されません**。
> これが「state がある」ことの効き目です。

---

### 3.4 C 段のあとにやること

Cloud Run は立ちましたが、この時点では **DB にテーブルが無く、バケットも空**です。
D 段に進むと証明書待ちで 15〜60 分止まるので、その前に中身を揃えます。

**マイグレーション**（Job の定義は Terraform 側 / 4.2）

```bash
gcloud run jobs execute ebook-migrate --region asia-northeast1 --wait
```

**管理者ユーザ**（Job にできないのでプロキシ経由 / 4.3）

```bash
docker compose --profile prod-db up -d cloudsql-proxy

docker compose exec \
  -e DB_HOST=cloudsql-proxy -e DB_PORT=3306 \
  -e DB_DATABASE=ebooks -e DB_USERNAME=ebooks \
  -e DB_PASSWORD="$(gcloud secrets versions access latest --secret=ebook-db-password)" \
  backend php artisan admin:user admin@example.com --name=管理者
```

**静的ファイル**（リポジトリルートから）

```bash
docker compose run --rm tools php tools/bin/generate-docs-index.php

gcloud storage rsync -r frontend/public/ gs://my-project-book-509215-frontend/ \
  --cache-control="public, max-age=300"

gcloud storage rsync -r cdn/public/ gs://my-project-book-509215-cdn/ \
  --cache-control="public, max-age=31536000, immutable"

gcloud storage rsync -r docs/public/ gs://my-project-book-509215-docs/ \
  --cache-control="public, max-age=300"

# .md は既定で text/markdown になり、「ソース」リンクがダウンロードになる。
# rsync は同期済みのファイルをスキップするので、--content-type を後から付けても
# 効かない。アップロード後に objects update で上書きする
gcloud storage objects update "gs://my-project-book-509215-docs/md/*.md" \
  --content-type=text/plain
```

バケット名は `terraform output buckets` で確認できます。
`<プロジェクトID>-<用途>` で組み立てているのは、**バケット名がグローバルに一意**だからです。

`rsync` は既定で削除を行いません。消えたファイルをバケットからも消したい場合だけ
`--delete-unmatched-destination-objects` を付けます。

#### C 段の完了条件

| | 確認方法 | 期待 |
|---|---|---|
| Cloud Run | `gcloud run services list --region asia-northeast1` | `ebook-api` / `ebook-admin` が Ready |
| マイグレーション | 4.1 の phpMyAdmin | `books` / `book_pages` / `users` / `sessions` がある |
| 管理者ユーザ | 同上 | `users` に 1 件 |
| 静的ファイル | `gcloud storage ls gs://my-project-book-509215-frontend/` | `index.html` などがある |
| docs の一覧 | `gcloud storage ls gs://my-project-book-509215-docs/index.json` | 存在する |

この時点では **LB がまだ無いので、ブラウザからは何も見えません**。
`*.run.app` も `ingress` で塞いであるため直接は叩けません。確認は上の表の手段で行います。

---

## 4. Terraform 以外に必要なもの

設計を読み直して見つかった、**コード以外の積み残し**です。

### 4.1 本番 DB の中身をどう見るか

Cloud SQL にはパブリック IP を持たせますが、**IP 許可リスト（`authorized_networks`）は空**にします。
そのため素の `mysql -h <IP>` では繋がりません。接続できるのは **Cloud SQL Auth Proxy 経由だけ**で、
利用には IAM の `roles/cloudsql.client` が要ります。認証をネットワーク層ではなく IAM に寄せる形です。

> **IP を消してはいけません。** Cloud Run の Unix ソケット接続は内部で同じプロキシが動いており、
> 実際のデータ通信はインスタンスの IP に対して行われます。プライベート IP を使うには VPC と
> Direct VPC egress が要るので、`ipv4_enabled = false` にすると **Cloud Run 自身が繋がらなくなります**。
> しかも Cloud SQL の作成は成功するため、C 段まで気づけません。

手元から見るときはプロキシでローカルポートに生やします。

```bash
brew install cloud-sql-proxy
cloud-sql-proxy my-project-book-509215:asia-northeast1:ebook-db --port 3307
```

あとはローカルの MySQL と同じように扱えます。

```bash
gcloud secrets versions access latest --secret=ebook-db-password   # パスワード
mysql -h 127.0.0.1 -P 3307 -u ebooks -p ebooks
```

GUI で見たいときは、**`compose.yaml` に本番用の phpMyAdmin を用意してあります**。

```bash
docker compose --profile prod-db up -d    # → http://localhost:8084
```

| | 開発用 | 本番用 |
|---|---|---|
| URL | `localhost:8083` | `localhost:8084` |
| 接続先 | `db` コンテナ | Cloud SQL（`cloudsql-proxy` 経由） |
| ログイン | 自動 | **毎回手入力** |
| 起動 | `docker compose up -d` | `--profile prod-db` を付けたときだけ |

**本番側だけ自動ログインを外してあります。** 見た目の似た phpMyAdmin が 2 つ並ぶので、
「開発のつもりで本番の行を消す」事故が起こりえます。パスワードを取りに行く一手間が、
そのまま「いま本番を触っている」という確認になります。

なお **phpMyAdmin を本番に置く案は採りません**。Cloud Run と証明書ドメインと DNS が
1 つずつ増えるうえ、5.3 で Cloud Armor を入れない方針にしたため
**認証が DB のパスワード 1 枚だけ**になります。管理画面（`/admin/*`）をレート制限まで入れて
守ったのに、その隣に DB を直接触れる口を無防備で開けることになり、一貫しません。

`artisan` を本番 DB に向けて流す場合も同じ経路が使えます。

```bash
DB_HOST=127.0.0.1 DB_PORT=3307 php artisan migrate --force
```

### 4.2 マイグレーションの実行

コンテナ起動時には流しません（Step.07 の 10）。Cloud Run はインスタンスが同時に複数立つので、
起動のたびに `migrate` すると競合します。同じイメージを **Cloud Run Jobs** として別に起動します。

Job の定義は `modules/job` に置き、Terraform で管理します。何度も実行するものなので、
`gcloud` で作ると引数や環境変数が手元の履歴にしか残りません。

```hcl
module "migrate_job" {
  source = "../../modules/job"

  name  = "ebook-migrate"
  image = "${module.registry.url}/api:v1"   # サービスと同じイメージ
  args  = ["artisan", "migrate", "--force"]

  cloudsql_connection_name = module.database.connection_name
  secret_env               = local.common_secret_env
}
```

`entrypoint.sh` は第 1 引数が `supervisord` 以外なら **oneshot モード**で動き、
nginx の設定生成や `config:cache` を飛ばします。そのため Web 用の環境変数
（`CDN_BASE_URL` など）は渡しません。`APP_KEY` だけは起動時に必ず要求されます。

**作っただけでは何も起きません。** 実行は明示的に叩いたときだけです。

```bash
gcloud run jobs execute ebook-migrate --region asia-northeast1 --wait
```

`max_retries = 0` にしてあります。マイグレーションは途中まで流れている可能性があるため、
自動で二度流さず人が確認します。

### 4.3 管理者ユーザの作成

**このコマンドは Job にできません。** `artisan admin:user` は
`Laravel\Prompts\password()` でパスワードを対話的に聞く作りで（履歴に残さないため / Step.06）、
TTY の無い Cloud Run Jobs では動きません。

4.1 のプロキシ経由で、手元から本番 DB に向けて流します。

```bash
docker compose --profile prod-db up -d cloudsql-proxy

docker compose exec \
  -e DB_HOST=cloudsql-proxy -e DB_PORT=3306 \
  -e DB_DATABASE=ebooks -e DB_USERNAME=ebooks \
  -e DB_PASSWORD="$(gcloud secrets versions access latest --secret=ebook-db-password)" \
  backend php artisan admin:user admin@example.com --name=管理者
```

`backend` と `cloudsql-proxy` は同じ compose ネットワークにいるので、サービス名で解決できます。
パスワードはコマンド置換で渡すため、**シェルの履歴には `$(gcloud ...)` の形しか残りません**。

### 4.4 Secret Manager への値の投入

`APP_KEY` を手で投入します。箱（`google_secret_manager_secret`）は Terraform が作るので、
値を入れるだけです。DB パスワードは Terraform が生成して投入するため、この作業は要りません（5.4）。

```bash
php artisan key:generate --show | \
  gcloud secrets versions add ebook-app-key --data-file=-
```

---

## 5. 決めていないこと

着手前に決めておく必要がある項目です。決まったものは節を分けて残します。

| | 選択肢 | メモ |
|---|---|---|
| イメージのタグ | `v1` 固定か、コミットハッシュか | ロールバックのしやすさに効く。CI を入れる段で決める |

### 5.1 ドメインまわりで必要な作業

`furusawa.work` は Cloud DNS 上にあり、そのゾーンは今回のプロジェクトに属しています。
レジストラ側の NS も Google を向いているため、**レジストラで行う作業はありません。**

```
レジストラ       お名前.com（GMO）。有効期限 2027-02-15
NS               ns-cloud-a1〜a4.googledomains.com
Cloud DNS ゾーン  furusawa-work (furusawa.work.)   … my-project-book-509215 内
ゾーン内レコード   NS と SOA のみ
ebook.furusawa.work   A / NS とも未設定
```

このステップで行うのは、**`ebook.` 配下の A レコードを既存ゾーンに追加すること**だけです。
`ebook.furusawa.work` の専用ゾーンは作りません。

| | 採用：既存ゾーンに追加 | 不採用：専用ゾーン + 委任 |
|---|---|---|
| ゾーン数 | 1（既存のまま） | 2 |
| 費用 | 追加 0 円 | +約 30 円/月 |
| 委任（NS レコード） | 不要 | 親ゾーンに NS を足す |
| 名前解決の段数 | 1 段 | 2 段（伝播待ちが増える） |
| 証明書発行 | 速い | 委任の伝播分だけ遅く、失敗要因が 1 つ増える |

親ゾーンに他のサービスが同居していれば隔離性に意味がありますが、**中身が NS と SOA だけ**なので利点がありません。

Terraform 側は、**手で作られた既存ゾーンを作らずに参照**します（import は不要）。

```hcl
data "google_dns_managed_zone" "root" {
  name = "furusawa-work"
}

resource "google_dns_record_set" "api" {
  managed_zone = data.google_dns_managed_zone.root.name
  name         = "api.ebook.${data.google_dns_managed_zone.root.dns_name}"  # api.ebook.furusawa.work.
  type         = "A"
  ttl          = 300
  rrdatas      = [google_compute_global_address.lb.address]
}
```

**親ゾーンが Terraform の管理外であることは、むしろ安全側に働きます。** `terraform destroy` してもゾーン自体は残り、ドメインが死にません。

**実施のタイミング。** A レコードの中身は LB のグローバル静的 IP なので、**IP を確保するまで書けません**。
この作業は LB 一式を作る段でまとめて行い、レコードが揃ってから証明書が `ACTIVE` になるのを待ちます。

### 5.2 `min-instances` は 1 で始める

**`min_instance_count = 1`** にします。0 だとリクエストが無い間インスタンスが落ち、
次のアクセスでコールドスタート（Laravel の起動と Cloud SQL への接続）が入るためです。

ただし **1 にすると常時課金になります**。Step.07 の 7 章は `min-instances=0` を前提に
「Cloud Run は無料枠に収まる」と書いているので、**その前提が崩れます**。
まず 1 で動かして実際の請求額を見てから、0 に落とすか判断します。

### 5.3 admin の保護に Cloud Armor は入れない

`admin.` の前段に IP 許可リストや IAP は置かず、**アプリ側の防御だけで守ります**。
根拠は Step.07 の 6 章で入れた次の 2 つです。

- セッション認証（ログイン試行のレート制限つき）
- `ADMIN_HOST` によるホスト限定。`api.` に `/admin/*` を投げても届かない

固定 IP が確保できる環境になったら Cloud Armor を足せますが、LB があるので**後付けできます**。
いま入れるとポリシー単位の固定費が増えるうえ、作業場所が変わるたびに許可リストの更新が要ります。

### 5.4 シークレットは種類ごとに扱いを変える

Step.07 の 4.4 は「Terraform で乱数を生成して Secret Manager に入れる」、
このドキュメントの当初案は「箱だけ Terraform、値は手投入」としていましたが、
**DB パスワードに後者は使えません。** `google_sql_user` を Terraform で作る以上、
手投入した値を `data` で読み戻せば結局 state に載るからです。

そこで**種類ごとに分けます**。

| | Terraform が値を使うか | 扱い | state |
|---|---|---|---|
| DB パスワード | 使う（`google_sql_user`） | `random_password` で生成し Secret Manager へ | **残る** |
| `APP_KEY` | 使わない（Cloud Run が参照するだけ） | 箱だけ作り、値は `gcloud secrets versions add` | 残らない |

`APP_KEY` は Terraform 側に値を使う相手がいないので、箱だけ作れば本当に state に入りません。
DB パスワードのほうは state に残りますが、**tfstate バケットは非公開・バージョニング・
`public_access_prevention = enforced`** で固めてあり、Step.07 の 4.4 が挙げた前提を満たしています。

より厳密にやるなら `password_wo`（書き込み専用引数）と `ephemeral` 変数の組み合わせで
state から完全に外せます。provider 7.46 で使えることは確認済みですが、
**apply のたびに値を渡す必要があり、渡し忘れと版ずれの事故が起きうる**ため今回は採りません。

---

## 現時点の未確認事項

- Step.07 の 3 章は DNS ゾーンを `google_dns_managed_zone` で新規作成する前提ですが、5.1 の通り**既存ゾーンを `data` で参照する**形に変わります
- backend bucket の index.html 解決（Step.07 の 8 章）は実機確認が必要です
- backend bucket に IAP を付けられるかは未確認です
- コストは Step.07 の 7 章の試算のみで、実測していません
