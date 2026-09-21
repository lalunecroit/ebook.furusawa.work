# feat(backend/cdn): 書籍情報を API から取得する

Step.01 のビューアはページ数も画像パスも `reader.js` にベタ書きでした。
これを **API (Laravel) が返す JSON** に置き換え、画像は **CDN 相当の nginx** から配る構成にしました。
DB はまだ入れていません（書籍データは PHP 側にベタ書き）。

## 構成

コンテナは 2 つから 4 つになりました。

```
                 ┌──────────────────────────┐
ブラウザ ────────▶│ frontend  :8080  (nginx) │  ビューア本体 (HTML/CSS/JS)
    │            └──────────────────────────┘
    │            ┌──────────────────────────┐
    ├─ fetch ───▶│ backend   :8000  (PHP)   │  書誌情報・ページ一覧 (JSON)
    │            └──────────────────────────┘
    │            ┌──────────────────────────┐
    └─ <img> ───▶│ cdn       :8082  (nginx) │  ページ画像
                 └──────────────────────────┘
                 ┌──────────────────────────┐
                 │ docs      :8081  (nginx) │  このドキュメント
                 └──────────────────────────┘
```

**画像は API を経由しません。** API が返すのは画像の URL だけで、
ブラウザはその URL を `<img src>` に入れて CDN から直接読みます。
PHP を画像の通り道にしないことで、API は JSON を返すだけの軽い処理に保てます。

## 変更ファイル

| パス | 役割 |
|---|---|
| `compose.yaml` | `cdn` / `backend` サービスを追加（コメントで確保していた枠を実装） |
| `cdn/nginx.conf` | 画像配信専用の設定（圧縮・長期キャッシュ・拡張子制限） |
| `cdn/public/books/sample/page-01..10.svg` | ページ画像の置き場。`/books/{slug}/` 構成 |
| `backend/` | Laravel 13.32 / PHP 8.4。`laravel new` の雛形 |
| `backend/Dockerfile` | `php:8.4-cli-alpine` + `artisan serve` |
| `backend/routes/api.php` | `/api/books/{slug}` と `/api/books/{slug}/pages` |
| `backend/app/Http/Controllers/Api/BookController.php` | 書籍 API 本体。書籍データをベタ書きで保持 |
| `backend/config/cdn.php` | 画像 URL 組み立て用のベース URL |
| `backend/bootstrap/app.php` | `api.php` の読み込みと、API の例外を JSON で返す設定 |
| `frontend/public/js/reader.js` | `CONFIG` の固定値を API 取得に置き換え |
| `frontend/public/css/style.css` | `.fatal`（起動失敗時の表示）を追加 |

## API

### エンドポイント

| メソッド | パス | 返すもの |
|---|---|---|
| `GET` | `/api/books/{slug}` | 書誌情報 + ページ一覧。ビューアはこれ1本で起動できる |
| `GET` | `/api/books/{slug}/pages` | ページ一覧のみ |

```json
{
  "data": {
    "slug": "sample",
    "title": "Docker で作る電子書籍サービス",
    "description": "フロントエンド編。ページめくりビューアの動作確認用サンプル。",
    "published_at": "2026-09-20",
    "total_pages": 10,
    "pages": [
      {
        "page_no": 1,
        "img_path": "/books/sample/page-01.svg",
        "updated_at": "2026-09-20T20:00:00+00:00",
        "url": "http://localhost:8082/books/sample/page-01.svg?v=1758398400"
      }
    ]
  }
}
```

`img_path` と `url` を両方返しています。`img_path` は CDN のドキュメントルートからの相対パス（＝ DB に保存する値）、`url` はブラウザがそのまま使える完成形です。フロントは `url` だけ見ます。

### データはまだ PHP にベタ書き

`BookController::BOOKS` に定数として持たせています。
ただし構造は将来のテーブルに合わせてあり、DB を入れる段階でこの定数を Eloquent のクエリに差し替えるだけで済むようにしています。

| ベタ書きのキー | 対応する想定テーブル |
|---|---|
| 配列のキー (`'sample'`) | `books.code` |
| `title` / `description` / `published_at` | `books` の各カラム |
| `pages[].page_no` / `img_path` / `updated_at` | `book_pages` の各カラム |

### CDN ベース URL の渡し方

```php
// backend/config/cdn.php
'base_url' => rtrim(env('CDN_BASE_URL', 'http://localhost:8082'), '/'),
```

compose から `CDN_BASE_URL: "http://localhost:8082"` を渡しています。
ここは**コンテナ名 (`http://cdn`) ではいけません。** この URL を実際に叩くのは PHP ではなくブラウザなので、ホスト側から見えるアドレスである必要があります。

## キャッシュ戦略

このステップの主題です。画像は「1年キャッシュさせる。差し替えたら URL を変える」という方針で組んでいます。

### CDN 側：長く持たせる

```nginx
location ~* \.(svg|png|jpe?g|webp|avif|gif|ico)$ {
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    add_header Access-Control-Allow-Origin "*" always;
    access_log off;
}
```

`immutable` は「このURLの中身は絶対に変わらないので、リロードしても再検証するな」という宣言です。
`max-age` だけだとユーザーがリロードしたとき条件付きリクエスト（`If-Modified-Since`）が飛びますが、`immutable` を付けるとそれも止まります。

`expires` ディレクティブは使っていません。`expires` と `add_header Cache-Control` を併用すると `Cache-Control` ヘッダが 2 行出てしまうため、1 行にまとめています。

### API 側：URL を変える

CDN 側で「絶対に変わらない」と宣言した以上、画像を差し替えても同じ URL のままではブラウザは取りに来ません。
そこで `book_pages.updated_at` を Unix time にして `?v=` に載せています。

```php
$version = Carbon::parse($page['updated_at'])->getTimestamp();
'url' => $base.$page['img_path'].'?v='.$version,
```

```
差し替え前  …/page-03.svg?v=1758398400
差し替え後  …/page-03.svg?v=1758484800   ← 別URL扱いになり、再取得される
```

更新したページの URL だけが変わるので、他のページのキャッシュは生きたままです。
API 自体はキャッシュさせていない（`Cache-Control: no-cache`）ので、新しい `?v=` は次のリロードで届きます。

### 圧縮

```nginx
gzip_types      image/svg+xml;
gzip_static     on;
```

圧縮対象は SVG だけです。**PNG / JPEG / WebP / AVIF は既に圧縮済みなので、gzip をかけても縮まず CPU を捨てるだけ**なので対象に入れていません。

`gzip_static on` は、同じディレクトリに `page-01.svg.gz` があればそれをそのまま返す設定です。今は `.gz` を置いていないので実行時圧縮になりますが、ページ枚数が増えたら事前生成に切り替えられます。

### その他の設定

| 設定 | 理由 |
|---|---|
| `open_file_cache` | 同じファイルを繰り返し配るので、メタ情報をキャッシュして `stat` の回数を減らす |
| `sendfile off` | Docker Desktop の仮想 FS と相性が悪く、差し替え後に古い内容が配信されるため（Step.01 の frontend と同じ理由） |
| `location ~* \.(php\|js\|json\|html?\|env\|sh)$ { return 404; }` | 画像しか置かない場所なので、それ以外は配らない |
| `autoindex on` | 開発時にページ一覧を確認するため。本番では外す |

## フロントエンドの変更

### CONFIG から固定値が消えた

```js
// Step.01
totalPages: 10,
pageSrc: (n) => `assets/pages/page-${String(n).padStart(2, '0')}.svg`,

// Step.02
api: { base: 'http://localhost:8000/api', slug: 'sample' },
totalPages: 0,                        // 起動時に API の total_pages で上書き
pageSrc: (n) => pageUrls.get(n) ?? '', // 中身は API レスポンス
```

`pageSrc` のシグネチャ（ページ番号 → URL）は変えていないので、**呼び出し側（`goTo()` / `preloadAround()` / `render()`）は 1 行も変わっていません。** Step.01 で画像URLの決定を 1 箇所に閉じ込めておいた効果です。

### init() が非同期になった

```js
async function init() {
  document.documentElement.style.setProperty('--flip-duration', `${CONFIG.animationMs}ms`);

  el.spinner.hidden = false;
  try {
    const book = await fetchBook();     // ← これが無いと何も表示できないので先に待つ
    if (book.title) {
      el.bookTitle.textContent = book.title;
      document.title = book.title;
    }
  } catch (e) {
    showFatal(`書誌情報を取得できませんでした: ${e.message}`);
    return;                             // ← ビューアは起動しない
  }
  el.spinner.hidden = true;

  state.current = loadProgress();
  // ... 以降は Step.01 のまま
}
```

API が落ちているときは `.fatal` を表示して終了します。`bindEvents()` を呼ばずに `return` するので、**操作を受け付けない状態で固まることがありません。** メッセージは `#liveRegion`（`aria-live`）にも流しているので、読み上げ環境でも失敗が伝わります。

書名も API から取り、`<h1>` と `document.title` を書き換えるようにしました。

### CORS

`frontend :8080` から `backend :8000` への fetch はクロスオリジンです。
Laravel 11 以降は `HandleCors` がグローバルミドルウェアに最初から入っており、フレームワーク既定の設定が `paths: ['api/*']` / `allowed_origins: ['*']` なので、**`config/cors.php` を置かなくても `/api/*` は通ります。**
認証を入れて Cookie を使う段階になったら `php artisan config:publish cors` で設定を出し、`supports_credentials` とオリジンの絞り込みが必要になります。

## 起動と動作確認

```bash
docker compose up -d --build
```

| URL | 内容 |
|---|---|
| http://localhost:8080 | ビューア |
| http://localhost:8000/api/books/sample | 書誌情報 + ページ一覧 |
| http://localhost:8082/books/sample/ | 画像一覧（autoindex） |
| http://localhost:8081 | このドキュメント |

```bash
# API が JSON を返すか
curl -s http://localhost:8000/api/books/sample | jq '.data.total_pages, .data.pages[0].url'

# CDN のキャッシュヘッダを確認
curl -sI http://localhost:8082/books/sample/page-01.svg | grep -i 'cache-control\|content-encoding'

# 存在しない slug が 404 JSON になるか
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/api/books/nope
```

**未確認:** ブラウザでの目視確認（めくり動作・書名の反映・API 停止時の `.fatal` 表示）は行えていません。レビュー時にご確認ください。

## 積み残し

- `frontend/public/assets/pages/*.svg` が参照されないまま残っています。画像は `cdn/public/` に複製する形で追加したため、フロント側の 10 枚は現在デッドファイルです（次のコミットで削除）
- `backend/` に `laravel new` の雛形がそのまま入っています（`User` モデル、`welcome.blade.php`、`vite.config.js`、Laravel の `README.md` など）。API しか使わないので整理の余地があります
- `artisan serve` はシングルプロセスの開発用サーバです。本番構成では php-fpm + nginx（または Octane）に差し替えます

## 次のステップ

- DB（MySQL）の追加と `books` / `book_pages` テーブルの作成。`BookController::BOOKS` を Eloquent に置き換え
- 書影・書籍一覧ページ
- しおりのサーバ保存（`saveProgress()` / `loadProgress()` を API 呼び出しに差し替え）

🤖 Generated with [Claude Code](https://claude.com/claude-code)
