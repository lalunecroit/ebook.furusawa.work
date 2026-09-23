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

# ③ イメージを push
gcloud auth configure-docker asia-northeast1-docker.pkg.dev
docker build -f backend/Dockerfile.prod -t <registry>/app/api:v1 ./backend
docker push <registry>/app/api:v1

# ④ 残り全部
terraform apply

# ⑤ 静的ファイルを流し込む
# ⑥ ネームサーバをレジストラ側に設定
```

---

## 4. Terraform 以外に必要なもの

設計を読み直して見つかった、**コード以外の積み残し**です。

### 4.1 `docs` の一覧生成（`index.json`）

`docs/public/index.html` はサイドバーの一覧を **nginx の autoindex** から組み立てています。
ローカルはこれで動きますが、**GCS に autoindex はありません**。

そのためのフォールバックとして `index.json` を読む実装が既に入っています。

```js
// index.json があればそれを使い、無ければ autoindex に落とす
const index = await loadIndex();
return index?.[dir] ?? listDirByAutoindex(dir);
```

Step.07 の適用順には `tools/bin/generate-docs-index.php` を流す前提で書かれていますが、
**このファイルはまだありません**（`tools/bin/` にあるのは `generate-sample-pages.php` だけ）。
GCS へ流す前に書く必要があります。

### 4.2 マイグレーションの実行

コンテナ起動時には流しません（Step.07 の 10）。Cloud Run Jobs を作るか、手で流します。

```bash
gcloud run jobs create ebook-migrate --image <IMAGE> \
  --command php --args artisan,migrate,--force
gcloud run jobs execute ebook-migrate
```

### 4.3 管理者ユーザの作成

`artisan admin:user` を本番 DB に対して実行する必要があります。4.2 と同じ経路で流せます。

### 4.4 Secret Manager への値の投入

`APP_KEY` と DB パスワードを入れます。**値を Terraform で作ると平文が tfstate に残る**ので、
シークレットの「箱」だけ Terraform で管理し、値は `gcloud secrets versions add` で手投入する方が安全です。

---

## 5. 決めていないこと

着手前に決めておく必要がある項目です。

| | 選択肢 | メモ |
|---|---|---|
| ドメイン | `ebook.furusawa.work` の DNS を Cloud DNS へ移すか | レジストラ側の NS 変更が要る |
| admin の保護 | Cloud Armor の IP 制限を入れるか | **入れない方針**。アプリ側の防御のみ（Step.07 の 15 / 16） |
| `min-instances` | 0 のままか | 0 ならコールドスタート、1 以上なら常時課金 |
| イメージのタグ | `v1` 固定か、コミットハッシュか | ロールバックのしやすさに効く |

---

## 現時点の未確認事項

- Terraform のコードはまだ 1 行も書いていません。Step.07 の骨子は `terraform validate` を通していません
- backend bucket の index.html 解決（Step.07 の 8 章）は実機確認が必要です
- backend bucket に IAP を付けられるかは未確認です
- コストは Step.07 の 7 章の試算のみで、実測していません
