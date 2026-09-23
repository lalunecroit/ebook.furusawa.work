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

# ⑤ 静的ファイルを流し込む（リポジトリルートから）
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

---

## 4. Terraform 以外に必要なもの

設計を読み直して見つかった、**コード以外の積み残し**です。

### 4.1 マイグレーションの実行

コンテナ起動時には流しません（Step.07 の 10）。Cloud Run Jobs を作るか、手で流します。

```bash
gcloud run jobs create ebook-migrate --image <IMAGE> \
  --command php --args artisan,migrate,--force
gcloud run jobs execute ebook-migrate
```

### 4.2 管理者ユーザの作成

`artisan admin:user` を本番 DB に対して実行する必要があります。4.1 と同じ経路で流せます。

### 4.3 Secret Manager への値の投入

`APP_KEY` と DB パスワードを入れます。**値を Terraform で作ると平文が tfstate に残る**ので、
シークレットの「箱」だけ Terraform で管理し、値は `gcloud secrets versions add` で手投入する方が安全です。

---

## 5. 決めていないこと

着手前に決めておく必要がある項目です。決まったものは節を分けて残します。

| | 選択肢 | メモ |
|---|---|---|
| admin の保護 | Cloud Armor の IP 制限を入れるか | **入れない方針**。アプリ側の防御のみ（Step.07 の 15 / 16） |
| `min-instances` | 0 のままか | 0 ならコールドスタート、1 以上なら常時課金 |
| イメージのタグ | `v1` 固定か、コミットハッシュか | ロールバックのしやすさに効く |

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

---

## 現時点の未確認事項

- Step.07 の 3 章は DNS ゾーンを `google_dns_managed_zone` で新規作成する前提ですが、5.1 の通り**既存ゾーンを `data` で参照する**形に変わります
- backend bucket の index.html 解決（Step.07 の 8 章）は実機確認が必要です
- backend bucket に IAP を付けられるかは未確認です
- コストは Step.07 の 7 章の試算のみで、実測していません
