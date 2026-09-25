# ci/cd: GitHub Actions で PR の検査と本番デプロイを自動化する

Step.08 で本番環境を作ってから、デプロイはすべて手でコマンドを叩いていました
（イメージの build → push → `gcloud run services update` → rsync → CDN のキャッシュ無効化）。

このステップでは GitHub Actions で、**PR の段階での検査（CI）** と **main へのマージを契機にした本番デプロイ（CD）** を自動化します。
GCP への認証には、**鍵を使わない Workload Identity Federation** を使います。

| # | コミット | 内容 |
|---|---|---|
| 1 | backend: Pint の違反を直す | CI で整形の検査を強制する前に、既存の違反をなくす |
| 2 | infra: gcloud が付けるデプロイ元の印を差分から外す | `client` / `client_version` を `ignore_changes` に |
| 3 | infra: GitHub Actions から鍵なしで入る仕組みを作る | Workload Identity Pool / Provider、CD 用と CI 用の SA |
| 4 | infra: lock ファイルに Linux 用のハッシュを足す | CI のランナー（linux_amd64）で `init` を通す |
| 5 | ci: PR 時にテストと terraform plan を流す | `ci-backend.yml` / `ci-infra.yml` |
| 6 | cd: main への push で本番へデプロイする | `cd.yml` |
| 7 | cd: docker の認証をトークン直渡しにする | `docker/login-action` にアクセストークンを渡す |
| 8 | infra: CD 用の subject を GitHub の不変 ID 形式に合わせる | なりすまし許可の条件を修正 |
| 9 | infra: CD の rsync に要るバケットの読み取り権限を足す | `storage.buckets.get` を追加 |

---

## 構成

```
PR（develop / main 向け）
  ├─ CI / backend    Pint → PHPUnit
  └─ CI / infra      fmt → init → validate → plan

main にマージ
  └─ CD
      ├─ backend     build → push → マイグレーション → api / admin の差し替え → 疎通確認
      └─ static      frontend → docs → CDN のキャッシュ無効化
```

| | 何をするか | いつ動くか | GCP の SA |
|---|---|---|---|
| **CI** | テスト、整形の検査、`terraform plan` | develop / main 向けの PR | `github-planner`（読み取り専用） |
| **CD** | 本番へのデプロイ | main への push | `github-deployer` |

**テストは PR の段階で流します。** main にマージされてから検査すると、壊れたコードが入ってから気づくことになるためです。

**インフラの変更は自動で apply しません。** CI は `plan` を表示するだけで、apply は差分を読んだうえで手で行います（Step.07 の 9 章の方針どおり）。

## 変更ファイル

| パス | 役割 |
|---|---|
| `.github/workflows/ci-backend.yml` | backend が変わった PR で、Pint と PHPUnit を流す |
| `.github/workflows/ci-infra.yml` | infra が変わった PR で、`fmt` / `validate` / `plan` を流す |
| `.github/workflows/cd.yml` | main への push で本番へデプロイする |
| `infra/modules/github_oidc/main.tf` | Workload Identity Pool / Provider、SA 2 つ、なりすまし許可 |
| `infra/modules/github_oidc/deployer.tf` | CD 用 SA の権限 |
| `infra/modules/github_oidc/planner.tf` | CI 用 SA の権限 |
| `infra/modules/github_oidc/variables.tf` / `outputs.tf` | 入力と、workflow に書く値の出力 |
| `infra/bootstrap/apis.tf` | `sts` / `iamcredentials` の API を有効化 |
| `infra/environments/prod/*` | モジュールの組み込み、リポジトリの ID |
| `infra/modules/frontdoor/outputs.tf` | CDN の無効化に使う URL マップ名を出力 |
| `infra/modules/api_service/main.tf` / `job/main.tf` | `ignore_changes` に `client` / `client_version` |
| `infra/**/.terraform.lock.hcl` | `linux_amd64` のハッシュを追加 |
| `backend/tests/Feature/**` / `app/Providers/AppServiceProvider.php` | Pint の違反を修正 |

---

## 1. Pint — CI で整形を強制する前に

Pint は Laravel 公式の PHP 整形ツールです（JavaScript の Prettier に近いもの）。
CI では `pint --test`（検査だけ）を流し、整形されていないコードが入ってきたら落とします。
**PR の差分がロジックの変更だけになる**のが目的です。

既存のコードに違反が 6 ファイルあったので、先に直しました。

**テストメソッド名（`php_unit_method_casing`）。** メソッド名を snake_case に揃える規則で、日本語は許容されますが、
大文字の英字があると引っかかります。Pint に自動で直させると `API` が `ap_i` になるなど読めない名前になるので、手で小文字にしました。

```php
public function test_公開APIはホストを問わず届く(): void   // 変更前
public function test_公開apiはホストを問わず届く(): void   // 変更後
```

**`new` の括弧（`new_with_parentheses`）。** 引数の無い `new InsecureCredentials()` の括弧を外す規則です。
PHP の標準規約（PSR-12）は括弧を**付ける**側ですが、Laravel は**外す**流儀で、Laravel 本体のソースも括弧なしが 227 箇所・ありが 14 箇所です。
Laravel のプロジェクトなので Laravel の流儀（Pint の既定）に合わせ、`pint.json` は置いていません。

## 2. 鍵なしで GCP に入る — Workload Identity Federation

### 仕組み

サービスアカウントの JSON 鍵を GitHub の Secrets に置く方式は、鍵が漏れた時点で誰でも GCP を操作できます。
Workload Identity Federation（WIF）では**鍵がそもそも存在しません**。

```
GitHub Actions ─ OIDC トークン ─▶ STS（検証して交換）─▶ SA になりすます ─▶ GCP を操作
```

GitHub は workflow の実行ごとに「このリポジトリの、このブランチで動いている」という**署名付きのトークン**を発行します。
GCP はそれを検証し、条件に合えば短命のトークンに交換します。

必要な API（`sts.googleapis.com` と `iamcredentials.googleapis.com`）は、ほかの API と同じく bootstrap で有効化しています。

### SA を 2 つに分ける

| SA | 使う場面 | 誰から使えるか |
|---|---|---|
| `github-deployer` | CD | **main への push からだけ** |
| `github-planner` | CI の `terraform plan` | このリポジトリの全ブランチ（読み取り専用） |

PR のブランチからデプロイ権限を使えてしまうと、**レビュー前のコードを本番に出せてしまう**ためです。

### 信頼の条件

3 段で絞っています。

**① Provider** — このリポジトリ以外からのトークンは、交換そのものを拒否します。

```hcl
attribute_condition = "assertion.repository_id == '1378122529'"
```

名前（`owner/name`）ではなく**数値 ID** で縛るのは、リポジトリを消して作り直すと他人が同じ名前を取れるためです。

**② `github-deployer`** — トークンの subject（「誰か」を表す値）が、main への push のものと一致したときだけ。

```
repo:lalunecroit@38752755/ebook.furusawa.work@1378122529:ref:refs/heads/main
```

`ref` 属性だけで縛らず subject で縛るのは、`pull_request_target` のように **PR 起点なのに ref が main になる**イベントを通さないためです。
そちらの subject は `…:pull_request` になるので一致しません。

**③ `github-planner`** — `repository_id` 属性が一致すれば、どのブランチからでも。
フォークからの PR には GitHub が OIDC トークンを発行しないので、外部の人は使えません。

> **subject の形式はリポジトリの設定で変わります。** このリポジトリは「不変 ID 付き」（`use_immutable_subject: true`）で、
> 所有者名とリポジトリ名の後ろに数値 ID が付きます。名前だけの形式（`repo:owner/name:…`）で縛ると一致せず、なりすましが 403 になります。
>
> ```bash
> gh api repos/lalunecroit/ebook.furusawa.work/actions/oidc/customization/sub
> # {"use_default":true,"use_immutable_subject":true,
> #  "sub_claim_prefix":"repo:lalunecroit@38752755/ebook.furusawa.work@1378122529"}
> ```
>
> 不変 ID 形式は、リポジトリが消されて同じ名前で作り直されても一致しないので、名前だけの形式より安全です。

### 権限

`roles/editor` のような広い役割は渡さず、**触るリソースにだけ**必要な役割を付けています。

| SA | 権限 | 対象 |
|---|---|---|
| deployer | `artifactregistry.writer` | Artifact Registry の `app` だけ |
| | `run.developer` | `ebook-api` / `ebook-admin` / `ebook-migrate` だけ |
| | `iam.serviceAccountUser`（actAs） | 上の 3 つの実行時 SA だけ |
| | `storage.objectAdmin` + `storage.legacyBucketReader` | `frontend` / `docs` バケットだけ（**`cdn` は除外**） |
| | カスタムロール `cdnCacheInvalidator` | URL マップの読み取りとキャッシュ無効化だけ |
| planner | `viewer` + `iam.securityReviewer` | プロジェクト全体（読み取り） |
| | `storage.objectViewer` | tfstate バケット |
| | `secretmanager.secretAccessor` | DB パスワードの Secret だけ |

**actAs が要る理由。** Cloud Run の新しいリビジョンは、実行時の SA（`ebook-api` など）として動きます。
デプロイする側がその SA に対する `iam.serviceAccounts.actAs` を持っていないと、イメージの差し替え自体が権限エラーになります。

**`legacyBucketReader` が要る理由。** `gcloud storage rsync` は転送の前にバケット自体のメタデータを読みます（`storage.buckets.get`）。
`objectAdmin` はオブジェクトの操作だけで、これを含みません。`legacyBucketReader` はバケットの読み取りとオブジェクトの一覧だけの役割で、
バケットの設定や IAM は変えられないので、`storage.admin` まで上げずに済みます。

**CDN の無効化はカスタムロール。** 既成の役割だと `compute.loadBalancerAdmin` まで上げる必要があり、LB の設定そのものを書き換えられてしまいます。
無効化に要る 3 権限（`compute.urlMaps.get` / `compute.urlMaps.invalidateCache` / `compute.globalOperations.get`）だけのロールを作りました。

**`cdn` バケットを CD の対象から外している理由。** 本番の画像は管理画面からアップロードされるもので、
リポジトリの `cdn/public/` はサンプルです。デプロイのたびに rsync すると本番のデータと混ざります。

> **planner は DB パスワードを読めます。** plan は state を読みますが、state には DB パスワードが平文で入っています（Step.08 の 5.4）。
> また Terraform が値を管理している Secret は、refresh で実際の値を読みにいきます。
> つまりこのリポジトリに push できる人は、間接的に DB パスワードを読めることになります。
> 個人のリポジトリなので許容していますが、共同作業者を増やすときは見直しが要ります。

### gcloud が付ける印を差分から外す

`gcloud run services update` でデプロイすると、gcloud が Cloud Run に `client = "gcloud"` / `client_version` という印を書き込みます。
Terraform はそれを消そうとするので、**放っておくとデプロイのたびに plan に差分が出続けます**。
イメージと同じく「デプロイする側が持つ値」として `ignore_changes` に入れました。

```hcl
lifecycle {
  ignore_changes = [
    template[0].containers[0].image,
    client,
    client_version,
  ]
}
```

---

## 3. CI — PR の段階で止める

### backend（`ci-backend.yml`）

`backend/` が変わった PR で、Pint → PHPUnit を流します。

**DB サービスは立てていません。** `phpunit.xml` が SQLite のインメモリを強制しているので（Step.03 で入れたもの）、PHP があればテストは通ります。
compose の環境変数に守られて通っていないか、compose の外で `.env.example` から作った `.env` だけで流して、86 件すべて通ることを確かめています。

**`APP_KEY` は CI で作ります。** ローカルでは entrypoint が `.env` に生成していますが、CI にはそれが無く、
セッションやログインのテストが `APP_KEY` を必要とします。

```yaml
- run: cp .env.example .env
- run: composer install --no-interaction --prefer-dist --no-progress
- run: php artisan key:generate
- run: ./vendor/bin/pint --test
- run: php artisan test
```

### infra（`ci-infra.yml`）

`infra/` が変わった PR で、`fmt` → `init` → `validate` → `plan` を流します。bootstrap と prod の 2 つを並列で。

**`plan` は `-lock=false` で流します。** planner には state への書き込み権限が無く、ロックファイルを作れません。
読むだけの plan がロックを取らなくても、state は壊れません。

**差分があっても失敗にしません。** `-detailed-exitcode` の終了コードは「0 差分なし / 1 エラー / 2 差分あり」で、
infra を変える PR で差分が出るのは当然なので、1 のときだけ落とします。

**plan の結果は実行ページの Summary に出します。** 差分の有無と plan の全文が、PR の Checks から 1 クリックで見られます。
公開リポジトリなので誰でも見られますが、`sensitive` な値は Terraform が伏せて出します。

**フォークからの PR では plan を流しません。** OIDC トークンが発行されないので、流しても必ず失敗します。

**lock ファイルは書き換えさせません**（`-lockfile=readonly`）。そのかわり、手元（Mac）で作った lock ファイルに
Linux 用のハッシュを足しておく必要があります。

```bash
terraform providers lock -platform=linux_amd64 -platform=darwin_arm64
```

---

## 4. CD — main へのマージで本番へ

### backend ジョブ

```
イメージを build して push   タグはコミットハッシュ
マイグレーション             ebook-migrate を新しいイメージに差し替えて実行
api と admin を差し替える
疎通確認                     /api/health?deep=1（DB まで）と管理画面のログイン画面
```

**タグはコミットハッシュ。** どのコミットが本番で動いているか一目で分かり、戻すときもタグを差し替えるだけです。
Step.08 の 5 章に残していた未決事項「`v1` 固定か、コミットハッシュか」は、ここで決着しました。

**マイグレーションは新しいコードより先に流します。** 新しいコードが新しいテーブル定義を前提にしていても、切り替えた瞬間に壊れないためです。
ただし列を消すような変更は、古いコードが動いている間に壊れるので、段階を分けて出す必要があります。

**`--platform linux/amd64` は要りません。** 手元の Apple Silicon と違い、GitHub のランナーは amd64 です。
Docker のレイヤは GitHub 側にキャッシュするので（`cache-from: type=gha`）、2 回目以降は composer install をやり直しません。

**Docker の認証はトークンを直接渡します。** `gcloud auth configure-docker` のような補助コマンド経由ではなく、
auth アクションにアクセストークンを出させて `docker/login-action` に渡します（auth アクションの公式の推奨）。

```yaml
- id: auth
  uses: google-github-actions/auth@v3
  with:
    token_format: access_token
    ...
- uses: docker/login-action@v4
  with:
    registry: asia-northeast1-docker.pkg.dev
    username: oauth2accesstoken
    password: ${{ steps.auth.outputs.access_token }}
```

### static ジョブ

backend が成功してから動きます。画面が新しい API を前提にしていることがあるためです。

```
frontend   gcloud storage rsync
docs       index.json を生成 → rsync → .md の Content-Type を text/plain に
CDN        www と docs のキャッシュを無効化
```

**CDN の無効化は `www` と `docs` だけ。** `cdn.` の画像は `?v=` で URL が変わる仕組みなので消す必要がなく、消すと全画像がオリジンから取り直しになります。

```bash
gcloud compute url-maps invalidate-cdn-cache ebook --host www.ebook.furusawa.work --path "/*" --async
```

### そのほか

- **デプロイは同時に 1 つだけ**（`concurrency`）。途中で取り消さず順番待ちにします。中途半端な状態を残さないためです
- **手動で流し直せます**（`workflow_dispatch`）。ただし GCP 側が main からしか受け付けないので、main 以外では動かないよう `if` でも止めています

---

## 運用の流れ

```
feature/stepNN ──PR──▶ develop ──PR──▶ main
                 CI              CI       └─▶ CD
```

`gh`（GitHub CLI）を使えば、PR の作成・CI の完了待ち・マージ・失敗したジョブのログの確認まで、ターミナルから行えます。

```bash
gh pr create --base develop --title "[Step11] …" --body "…"
gh pr checks --watch
gh pr merge --merge
gh run view <run-id> --log-failed
```

インフラの変更を含むときは、**develop にマージしたあと、main にマージする前に `terraform apply`** します。
CD は GCP の権限を前提に動くので、先に main に入れると権限の変更が間に合わずに落ちます。

---

## 動作確認

| 確認 | 結果 |
|---|---|
| CI / backend | Pint 60 ファイル PASS、PHPUnit 86 件通過 |
| CI / infra | `fmt` 通過、`plan` は bootstrap・prod とも完走（planner の権限が足りていることの確認） |
| CD / backend | build → push → マイグレーション → 差し替え → 疎通確認まで成功 |
| CD / static | frontend・docs の配置、CDN の無効化まで成功 |
| 本番のイメージ | `ebook-api` / `ebook-admin` とも main のコミットハッシュのタグ |
| `/api/health?deep=1` | `ok` / DB `ok` |
| `admin.` の `/` | `/admin/books` へ 302（Step.09 の変更が CD のイメージでも生きている） |
| docs | `.md` が `text/plain`、`index.json` に artifact 10 件 |

---

## ハマりどころ

| 症状 | 原因 |
|---|---|
| CD の push で `error getting credentials`（エラーの本文が空） | WIF の subject が一致せず、SA になりすませていない。資格情報ファイルを書くだけの auth ステップは成功に見え、Docker の補助コマンドが実際にトークンを交換する段階で失敗するので、本文の無いエラーになる |
| auth で `Permission 'iam.serviceAccounts.getAccessToken' denied` | 同上。subject を名前だけの形式で縛っていたが、このリポジトリは不変 ID 付きの形式で出している |
| rsync で `does not have storage.buckets.get access` | `objectAdmin` はバケットのメタデータの読み取りを含まない。`legacyBucketReader` を足す |
| デプロイのたびに plan に `client = "gcloud" -> null` が出る | gcloud が付ける印を Terraform が消そうとしている。`ignore_changes` に入れる |
| CI の `terraform init` がハッシュの不一致で落ちうる | Mac で作った lock ファイルに `darwin_arm64` のハッシュしか無い。`providers lock` で `linux_amd64` を足す |
| 手元から planner になりすまして plan を試せない | Owner でも `iam.serviceAccounts.getAccessToken` は含まれない。本番の IAM を書き換えてまで試さず、PR を出して CI で確かめる |
| CD を feature ブランチで試せない | deployer は main からしか使えないよう縛ってある。本番へのリリースそのものが初回の実行になる |

---

## 積み残し

| 項目 | 内容 |
|---|---|
| ブランチ保護 | CI が落ちてもマージできる状態。ただし `paths` で絞った workflow を必須チェックにすると、該当ファイルを触らない PR が永遠に待たされる |
| タグと Release | `0.9.0` まで。Step.10 / 11 のリリースが未作成 |
| `ubuntu-latest` | 2026-10-19 から Ubuntu 26 に切り替わる。固定するなら `ubuntu-24.04` |
| イメージの保持 | Artifact Registry の保持ポリシーは「直近 10 世代を残す」「タグ無しを 7 日で消す」で、コミットハッシュのタグ付きは消えない。期限を足す必要がある |
| GitHub の Environments | 使っていない。使うと subject が `…:environment:<name>` に変わるので、deployer のなりすまし許可も合わせて変える必要がある |
| Actions のバージョン固定 | major タグ（`@v7` など）で指定している。サプライチェーン対策を厳密にするならコミットハッシュで固定する |

🤖 Generated with [Claude Code](https://claude.com/claude-code)
