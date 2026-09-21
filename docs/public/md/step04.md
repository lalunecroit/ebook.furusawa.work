# feat(backend): 書籍 API を DB から返し、構成を本番寄りに整える

Step.03 でテーブルとモデルを用意したので、このステップで **`BookController` の定数を Eloquent に差し替え**ます。
そのうえで、Laravel の作法から外れていた箇所（ルートモデルバインディング・API Resource・モデルファクトリ）を整え、最後に `artisan serve` を **php-fpm + nginx** に置き換えました。

**API のレスポンス仕様は最後まで一度も変えていません。** キーの名前も構造もそのままなので、フロントエンドは 1 行も変更していません。

| # | コミット | 内容 |
|---|---|---|
| 1 | 電子書籍APIでデータをDBから取得 | 定数 → Eloquent、テスト 6 本の追加 |
| 2 | ルートモデルバインディングに切り替え | `{code}` → `{book}`、`#[RouteKey]` |
| 3 | booksテーブルの1件取得についてクエリ最適化 | 複合ユニークを端まで使う |
| 4 | API Resource を利用 | JSON の整形をコントローラから分離 |
| 5 | モデルファクトリの作成 | テストデータ生成を宣言的に |
| 6 | テスト用DBの作成を自動化 | `ebooks_test` を自動で用意する |
| 7 | php-fpm&nginxへ切り替え | `artisan serve` からの脱却 |
| 8 | テスト用DBがないときは作成 / 作成方法を変更 | 初期化スクリプト → PHPUnit の bootstrap |

## 1. 定数を Eloquent に差し替える

```php
$book = Book::with(['pages' => fn ($query) => $query->orderBy('page_no')])
    ->where('code', $code)
    ->first();

abort_if($book === null, 404, "Book [{$code}] not found.");
```

| 判断 | 理由 |
|---|---|
| `with()` で eager load | ページは必ず使う。外すとページ取得で追加クエリが走る（N+1） |
| `total_pages` は**実際の行数** | `pages_count` は一覧表示用の非正規化カラムで、ページを差し替えた直後などにズレうる |
| 削除済みの本は 404 | `SoftDeletes` のグローバルスコープが自動で除外する |

キャッシュバスターの元ネタも定数から `book_pages.updated_at` に変わりましたが、組み立て方は Step.02 のままです。Step.03 でマイグレーションに `ON UPDATE CURRENT_TIMESTAMP` を入れてあるので、生 SQL で更新した場合も `updated_at` は動きます。

## 2. ルートモデルバインディング

`{code}` という文字列で受けて自前で検索していた部分を、フレームワークに任せます。

```php
// モデル
#[RouteKey('code')]
class Book extends Model

// ルート
Route::get('/books/{book}', [BookController::class, 'show']);

// コントローラ
public function show(Book $book): BookResource
```

検索と 404 の記述がコントローラから消えました。**URL の形は変わりません**（`/api/books/sample`）。

### 何が名前で、何が型で決まるのか

`{book}` と書いたから `Book` モデルが選ばれる、のではありません。解決は `SubstituteBindings` ミドルウェアが行い、**型と名前で役割が分かれています**。

| 決めていること | 由来 |
|---|---|
| どのモデルクラスか | **型宣言** `Book` |
| どの URL パラメータの値を使うか | **引数名** `$book` ↔ `{book}` |
| どのカラムで検索するか | `#[RouteKey('code')]`、無ければ主キー `id` |
| 削除済みを含めるか | モデルのグローバルスコープ（`->withTrashed()` で変更可） |

Laravel はコントローラの引数をリフレクションで読み、`UrlRoutable` を実装した型の引数を探します。見つかったら引数名と同名の URL パラメータの値を取り、`Model::resolveRouteBinding()` で検索します。見つからなければ `ModelNotFoundException` を投げ、例外ハンドラが 404 に変換します。

注意点として、**名前が一致しないと例外も警告も出ずに素通りします**。その場合 DI コンテナが空のモデルを作って渡すため、`$book->exists === false` の状態でコードが進みます。パラメータ名を変えるときは引数名も必ず合わせてください。

## 3. 索引が効くようにする

バインディングが発行するクエリを確認したところ、複合ユニーク `(code, is_active)` の **`code` 側しか使えていませんでした**。

```sql
-- 既定 (SoftDeletes のグローバルスコープが付ける条件だけ)
select * from books where code = ? and books.deleted_at is null limit 1
```

`deleted_at` は索引に含まれていないため、行を読んでから判定することになります。

| 条件 | type | key_len | Extra |
|---|---|---|---|
| `code = ? AND deleted_at IS NULL` | `ref` | 258（`code` のみ） | **Using where** |
| `code = ? AND is_active = 1` | **`const`** | **260**（索引を端まで使用） | NULL |

生存行は `is_active = 1` と同値なので、バインディングの検索条件に足しました。

```php
public function resolveRouteBinding($value, $field = null)
{
    /** @var Builder<static> $query */
    $query = $this->resolveRouteBindingQuery($this, $value, $field);

    return $query->where('is_active', 1)->first();
}
```

オーバーライドしたのは `resolveRouteBinding()` だけです。`->withTrashed()` を付けたルートは `resolveSoftDeletableRouteBinding()` を通るので、削除済みが引けなくなる事故は起きません。

Step.03 で `is_active` を「部分ユニークを表現するための生成列」として入れましたが、**検索でも使える**のがこの構成の利点です。

## 4. API Resource

JSON の組み立てをコントローラから出しました。

```php
// app/Http/Resources/BookPageResource.php
public function toArray(Request $request): array
{
    return [
        'page_no' => $this->page_no,
        'img_path' => $this->img_path,
        'updated_at' => $this->updated_at->toIso8601String(),
        'url' => $this->url(),        // ?v= の組み立てはここ1箇所
    ];
}
```

```php
// コントローラは「取ってきて渡す」だけになる
public function show(Book $book): BookResource
{
    $this->loadPages($book);

    return new BookResource($book);
}
```

API Resource は「コントローラの共通処理置き場」ではなく、**モデル → JSON の変換を担当する層**（MVC の View、デザインパターンで言う Presenter）です。今回の実利は次の 2 点でした。

- **`?v=` の組み立てが 1 箇所に集まる**。書籍一覧 API や管理画面のプレビューでも同じ整形が必要になるため、コピーが増える前に出しておきたい
- `data` ラッパーが自動で付く（`JsonResource::$wrap` の既定値）

移行後、両エンドポイントの JSON を差分比較して**完全一致**を確認しています。

## 5. モデルファクトリ

テストデータの生成が手組みだったので、ファクトリに移しました。

```php
// 変更前: テストクラス内の21行のヘルパ
$book = Book::create([...]);
$book->pages()->createMany([
    ['page_no' => 1, 'img_path' => '/books/sample/page-01.svg'],
    ['page_no' => 2, 'img_path' => '/books/sample/page-02.svg'],
]);

// 変更後
Book::factory()->withPages(2)->create(['code' => 'sample', ...]);
```

`BookFactory` に状態を 3 つ用意しました。

| メソッド | 用途 |
|---|---|
| `withPages(int $count = 2)` | `page_no` を 1 から連番で振り、作成後に `pages_count` を実数へ更新 |
| `trashed()` | 論理削除済み |
| `unpublished()` | `published_at` が null |

`img_path` は**親の書籍の `code`** から組み立てています。ファクトリの state クロージャは `(属性, 親モデル)` を受け取れる（`Factory::getRawAttributes()` が `$state($carry, $parent)` と呼ぶ）ためです。

```php
public function forParentBook(): static
{
    return $this->state(fn (array $attributes, ?Book $book) => [
        'img_path' => $this->imgPath($book?->code ?? 'sample', $attributes['page_no'] ?? 1),
    ]);
}
```

実際に動かすと次のようになります。

```
code        : demo
pages_count : 3 (実ページ数: 3)
page 1 : /books/demo/page-01.svg
page 2 : /books/demo/page-02.svg
page 3 : /books/demo/page-03.svg
```

書籍一覧 API を作るときに `Book::factory()->count(20)->create()` と書けるので、ページネーションのテストが楽になります。

## 6. テスト

`BookApiTest` で 6 本。`RefreshDatabase` でテストごとに DB を作り直します。

| テスト | 確認していること |
|---|---|
| 書誌情報とページ一覧が返る | JSON の構造とキー、`total_pages`、ページの並び |
| 画像URLに CDN のベースURLとキャッシュバスターが付く | `config(['cdn.base_url' => ...])` を差し替えて `?v=` を検証 |
| ページ一覧だけを返すエンドポイント | `/pages` の件数 |
| 存在しない code は 404 | |
| ソフトデリート済みの書籍は 404 になり復元すると戻る | `SoftDeletes` のグローバルスコープ |
| 生存行の code 重複は弾かれ削除済みとは重複できる | Step.03 の `(code, is_active)` 複合ユニーク |

最後の 1 本は API ではなく**スキーマの振る舞い**のテストです。部分ユニークの仕掛けはアプリ側のコードに現れないので、テストで意図を残しておかないと、後からマイグレーションを触ったときに壊れても気づけません。

### sqlite と MySQL を切り替える

```bash
docker compose exec backend php artisan test                        # sqlite (:memory:)
docker compose exec backend php artisan test -c phpunit.mysql.xml   # MySQL (ebooks_test)
```

環境変数では切り替わりません。PHPUnit は XML の `<server>` をブートストラップ時に `$_SERVER` へ書き込むので、プロセスの環境変数より XML が優先されるためです。設定ファイルを分けるのが確実です。

### テスト用 DB の作成を自動化

`ebooks_test` を手で作っていたため、環境を作り直すと失われていました。ここは 2 段階で形が変わっています。

**最初の案: MySQL の初期化スクリプト**

`db/init/01-create-test-db.sql` を `/docker-entrypoint-initdb.d/` にマウントする方法です。ただし MySQL 公式イメージはこのディレクトリを**データディレクトリが空のとき（＝初回起動時）にしか実行しません**。すでにボリュームがある環境では動かず、`docker compose down -v` が必要になります。「テストを動かすために DB を作り直す」のは本末転倒なので、採用をやめました。

**採用した案: PHPUnit の bootstrap で作る**

```xml
<!-- phpunit.mysql.xml -->
<phpunit bootstrap="tests/bootstrap-mysql.php" ...>

<server name="DB_ROOT_USERNAME" value="root" force="true"/>
<server name="DB_ROOT_PASSWORD" value="root" force="true"/>
```

```php
// tests/bootstrap-mysql.php
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', $_SERVER['DB_HOST'], $_SERVER['DB_PORT']),
    $_SERVER['DB_ROOT_USERNAME'],
    $_SERVER['DB_ROOT_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$username}'@'%'");

require __DIR__.'/../vendor/autoload.php';
```

PHPUnit は `bootstrap` に指定したファイルを**テスト実行のたびに最初に読み込みます**。そこで DB を用意してから `vendor/autoload.php` を読ませる形にしました。

| 利点 | 内容 |
|---|---|
| いつ実行しても効く | ボリュームの状態に依存しない。既存環境でもそのまま動く |
| CI でも同じ手順 | service container 側の初期化スクリプトに頼らなくてよい |
| 冪等 | `CREATE DATABASE IF NOT EXISTS` と `GRANT` はいずれも何度流しても同じ結果 |

`root` の資格情報を使うのは、`MYSQL_USER` で作られる `ebooks` が `MYSQL_DATABASE`（＝`ebooks_local`）の権限しか持たず、**新しい DB を作れないため**です。`DB_ROOT_USERNAME` / `DB_ROOT_PASSWORD` も `<server>` で渡しています（ローカル開発専用の値です）。

なお `sqlite` 版（`phpunit.xml`）はインメモリなので、この準備は不要です。

## 7. php-fpm + nginx へ

`artisan serve` は **PHP のビルトインウェブサーバ（`php -S`）を起動するラッパー**で、開発用です。シングルプロセスで同時リクエストを捌けません。

```
変更前  ブラウザ ──▶ backend :8000 (php artisan serve)

変更後  ブラウザ ──▶ backend-web :8000 (nginx) ──fastcgi──▶ backend :9000 (php-fpm)
                     静的ファイルを返す                PHP の実行のみ
```

| サービス | 内容 |
|---|---|
| `backend` | `php:8.4-fpm-alpine`、`CMD ["php-fpm"]`。**ホストにポートを公開しない** |
| `backend-web`（新規） | `nginx:1.27-alpine`。`8000:80`、`/app/public` を配信 |

```nginx
root /app/public;                                   # 公開は public/ だけ

location / { try_files $uri $uri/ /index.php?$query_string; }

location ~ \.php$ {
    fastcgi_pass backend:9000;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

両コンテナで `./backend` を `/app` にマウントしているのがポイントです。`SCRIPT_FILENAME` は nginx が組み立てたパスを php-fpm が開くため、**パスが一致していなければ動きません**。

### clear_env の罠

php-fpm の既定は `clear_env = yes` で、**ワーカー起動時に環境変数をすべて捨てます**。これを知らないと compose の `DB_HOST=db` が届かず、`artisan serve` のときと同じ `Connection refused` になります。原因の仕組みは違うのに症状が同じ、という厄介な組み合わせです。

```ini
; backend/php-fpm.d/zz-app.conf
[www]
clear_env = no
```

`php-fpm.conf` は `include=/usr/local/etc/php-fpm.d/*.conf` で読み込み、**ファイル名順に後勝ち**で上書きします。`zz-` で始めているのは既定値の `www.conf` より後に読ませるためで、公式イメージ自身も `zz-docker.conf` という名前を使っています。

### 回避策の削除

Step.04 の途中まで必要だった `AppServiceProvider` の `ServeCommand::$passthroughVariables` への追加は、**`serve` を使わなくなったので削除しました**。`php-fpm` はマスタープロセスの環境をワーカーに渡せる（`clear_env = no`）ため、迂回が不要になります。

### 公開範囲

ドキュメントルートが `public/` に限定されるため、ソースや設定は配信されません。

```
/.env                     -> 403
/composer.json            -> 404
/app/Models/Book.php      -> 404
/storage/logs/laravel.log -> 403
```

## 動作確認

```bash
docker compose up -d --build
docker compose exec backend php artisan migrate:fresh --seed

curl -s http://localhost:8000/api/books/sample | jq '.data.total_pages, .data.pages[0].url'
```

| 確認 | 結果 |
|---|---|
| `GET /api/books/sample`・`/pages` | 200 |
| 存在しない code | 404 |
| レスポンス JSON | Resource 移行の前後で**完全一致** |
| 発行クエリ | **2 本**（本体 + ページ。N+1 なし） |
| 本体取得の EXPLAIN | `type: const` / `key_len: 260` / `Extra: NULL` |
| テスト（sqlite / MySQL） | どちらも 8 passed / 28 assertions |
| テスト用 DB | `ebooks_test` を落としてから MySQL 版を実行しても、bootstrap が作り直して 8 passed |
| ログ書き込み | php-fpm のワーカー（`www-data`）から `storage/logs` へ書き込み可 |
| ビューア | タイトル・画像・総ページ数すべて API 由来の値で表示。**フロントは無修正** |

## ハマりどころ

| 症状 | 原因 |
|---|---|
| `Connection refused`（HTTP だけ） | `artisan serve` が子プロセスに環境変数を渡さない。php-fpm では `clear_env = yes` が同じ症状を起こす |
| バインディングが効かず空のモデルが渡る | ルートパラメータ名と引数名の不一致。例外は出ない |
| 索引が部分的にしか効かない | `deleted_at IS NULL` は複合ユニークに含まれない。`is_active = 1` を使う |
| テストの接続先が切り替わらない | PHPUnit の XML が `$_SERVER` を上書きする。設定ファイルを分ける |
| MySQL の初期化 SQL が実行されない | `/docker-entrypoint-initdb.d/` はデータディレクトリが空のときだけ動く。既存環境には効かないので、テスト用 DB は bootstrap で作る |
| ブランチ切り替え後に 403 / 404 | Docker Desktop のバインドマウントが外れている。`docker compose up -d --force-recreate <service>` |

## 積み残し

- **フロントと API がオリジンで分かれている**。`reader.js` に `http://localhost:8000` が直書きされたままで、CORS も必要です。`backend-web` に frontend の配信を寄せるか、frontend 側に `/api` のプロキシを足せば、どちらも不要になります
- **書籍一覧 API が無い**。`pages_count` はそのための非正規化カラムですが、まだ使い道がありません
- 認証（`users` テーブルは雛形のまま残してあります）

## 次のステップ

- 書籍一覧 API と一覧ページ
- 同一オリジン化（`reader.js` の URL 直書きと CORS の解消）
- しおりのサーバ保存

🤖 Generated with [Claude Code](https://claude.com/claude-code)
