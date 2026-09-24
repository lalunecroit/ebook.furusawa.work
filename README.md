# ebook.furusawa.work

画像ベースの電子書籍サービス。ブラウザでページを捲り本を読めます。

| URL | 内容 |
|---|---|
| <https://ebook.furusawa.work> | 電子書籍サイト。機能：`書籍一覧` `書籍閲覧(ビューワ)` |
| <https://admin.ebook.furusawa.work> | 管理画面。機能：`書籍登録・編集` `ページ登録` |
| <https://docs.ebook.furusawa.work> | ドキュメント |

管理画面はテストアカウントを用意しています。

| アカウント | パスワード |
|---|---|
| `test@example.com` | `2xu4VV8RibVS` |

Docker Compose でローカル一式が立ち上がり、本番は Terraform で GCP に構築しています。

| 領域 | 採用技術 |
|---|---|
| フロントエンド | Vanilla JS / CSS。ビルドツールなし |
| バックエンド | PHP 8.4 / Laravel 13 |
| データベース | MySQL |
| インフラ | Docker Compose（ローカル） / Terraform + GCP（本番） |

> [!NOTE]
> - 学習教材のため、JS は未圧縮・未難読化
> - CI/CD 周りの実装はこれから

---

## 本番環境（GCP）

### 構成

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
| `www.ebook.furusawa.work` | GCS | 書籍一覧・ビューア |
| `api.ebook.furusawa.work` | Cloud Run (`ebook-api`) | 公開 API |
| `admin.ebook.furusawa.work` | Cloud Run (`ebook-admin`) | 管理画面 |
| `cdn.ebook.furusawa.work` | GCS | ページ画像 |
| `docs.ebook.furusawa.work` | GCS | ドキュメント |
| `ebook.furusawa.work` | ↑ www へ 301 | 素で叩かれたとき用 |

### GCP利用リソース一覧

| リソース | 内容 |
|---|---|
| 外部 Application LB | グローバル静的 IP 1 つ。転送ルールは 443 と 80（80 は 301 リダイレクト） |
| マネージド証明書 | 上記 6 ドメインを 1 枚に |
| Cloud CDN | GCS の 3 バケットで有効 |
| Cloud Run ×2 | `ebook-api` / `ebook-admin`。**同じイメージ**を環境変数と権限だけ変えて動かす |
| Cloud SQL | MySQL 8.0 / `db-f1-micro`。パブリック IP はあるが IP 許可リストは空 |
| Secret Manager | `APP_KEY` と DB パスワード。Cloud Run には値ではなく参照を渡す |
| Artifact Registry | コンテナイメージ |
| Cloud Run Jobs | `ebook-migrate`。マイグレーションは起動時に流さず単発で実行する |
| Cloud DNS | 既存ゾーンに A レコードを 6 本追加 |


### infra ディレクトリ

```
infra/
├── bootstrap/            tfstate 用バケットと API 有効化。単独で apply する
├── modules/
│   ├── registry/         Artifact Registry
│   ├── database/         Cloud SQL + Secret
│   ├── static_site/      GCS + backend bucket + Cloud CDN
│   ├── api_service/      Cloud Run + SA + IAM
│   ├── job/              Cloud Run Jobs
│   └── frontdoor/        LB + URL マップ + 証明書 + DNS
└── environments/prod/    上記を組み合わせる
```

---

## ローカル環境

### 立ち上げ

```bash
# 1. Docker コンテナの立ち上げ
docker compose up -d

# 2. Laravel が表示されるのを確認
#    初回は composer install とマイグレーションが走るため数分かかります
open http://localhost:8000

# 3. サンプルデータの投入
docker compose exec backend php artisan db:seed

# 4. Unitテスト
docker compose exec backend php artisan test
```

立ち上がったら以下が見られます。

| URL | 内容 |
|---|---|
| <http://localhost:8080> | 書籍一覧・ビューア |
| <http://localhost:8000/admin> | 管理画面 |
| <http://localhost:8081> | このリポジトリのドキュメント |
| <http://localhost:8083> | phpMyAdmin |

管理画面のアカウントです。

| アカウント | パスワード |
|---|---|
| `test@example.com` | `password` |

### 構成

```
                 ┌──────────────────────────┐
ブラウザ ────────▶│ frontend  :8080  (nginx) │  一覧 + ビューア
    │            └──────────────────────────┘
    │            ┌──────────────────────────┐   ┌──────────────────┐
    ├─ fetch ───▶│ backend-web :8000 (nginx)│──▶│ backend (php-fpm)│
    │            │   /api/*   公開 API      │   │ Laravel 13 + GD  │
    │            │   /admin/* 管理画面      │   └┬───────┬─────────┘
    │            └──────────────────────────┘    │       │
    │            ┌──────────────────────────┐    │       │
    └─ <img> ───▶│ cdn       :8082  (nginx) │◀───┘       ▼
                 └──────────────────────────┘   ┌──────────────────┐
                             ▲                  │ db  :3306 (MySQL)│
                 ┌───────────┴──────────────┐   └──────────────────┘
                 │ tools      (php-cli, GD) │            ▲
                 └──────────────────────────┘   ┌──────────────────┐
                 ┌──────────────────────────┐   │ phpmyadmin :8083 │
                 │ docs      :8081  (nginx) │   └──────────────────┘
                 └──────────────────────────┘
```

| サービス | ポート | 中身 | 役割 |
|---|---|---|---|
| `frontend` | 8080 | nginx | 書籍一覧（`index.html`）とビューア（`reader.html`） |
| `backend-web` | 8000 | nginx | HTTP の受け口。PHP は php-fpm へ渡す |
| `backend` | (9000・内部) | php-fpm + GD | 公開 API（`/api/*`）と管理画面（`/admin/*`） |
| `cdn` | 8082 | nginx | ページ画像。管理画面からの書き込み先 |
| `db` | 3306 | MySQL 8.4 | 書誌情報 |
| `phpmyadmin` | 8083 | phpMyAdmin | DB 管理画面 |
| `docs` | 8081 | nginx | ドキュメント |
| `tools` | — | php-cli + GD | サンプル画像の生成 |

### 追加コマンド

管理画面に新しいアカウントを用意したい場合は、以下コマンドを実行して下さい

```bash
docker compose exec backend php artisan admin:user new@example.com --name=新規アカウント
```

サンプルのページ画像はリポジトリに含まれていますが、作り直すこともできます。

```bash
docker compose run --rm tools
```
