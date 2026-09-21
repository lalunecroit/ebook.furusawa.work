# feat(db): MySQL の追加と書籍テーブルの設計

Step.02 の API は、書籍データを `BookController::BOOKS` という PHP の定数に持っていました。
このステップでは、その受け皿になる **MySQL コンテナ・テーブル・モデル**を用意します。

**API はまだベタ書きのままです。** Eloquent への差し替えは次のステップで行います。
先にスキーマを固めるのは、論理削除と一意制約の両立のように、データが入った後では変更に移行作業が伴う部分を決め切っておきたいためです。

## 構成

コンテナは 4 つから 6 つになりました。

```
                 ┌──────────────────────────┐
ブラウザ ────────▶│ frontend  :8080  (nginx) │  ビューア本体
    │            └──────────────────────────┘
    │            ┌──────────────────────────┐   ┌──────────────────┐
    ├─ fetch ───▶│ backend   :8000  (PHP)   │──▶│ db  :3306 (MySQL)│
    │            └──────────────────────────┘   └──────────────────┘
    │            ┌──────────────────────────┐            ▲
    └─ <img> ───▶│ cdn       :8082  (nginx) │            │
                 └──────────────────────────┘   ┌──────────────────┐
                 ┌──────────────────────────┐   │ phpmyadmin :8083 │
                 │ docs      :8081  (nginx) │   └──────────────────┘
                 └──────────────────────────┘
```

| サービス | ポート | イメージ | 役割 |
|---|---|---|---|
| `db` | 3306 | `mysql:8.4` | 書誌情報。名前付きボリューム `db-data` に永続化 |
| `phpmyadmin` | 8083 | `phpmyadmin:5.2` | DB 管理画面。アプリからは参照しない |

`db` にはヘルスチェック（`mysqladmin ping`）を入れ、`backend` と `phpmyadmin` は `condition: service_healthy` で待ちます。MySQL はコンテナが起動してから接続を受け付けるまでに時間があり、その間に `artisan` が繋ぎにいくと落ちるためです。

3306 をホストに公開しているのは、ホスト側から `artisan migrate` や GUI クライアントで直接繋ぐためです。

### 接続情報は compose から渡す

```yaml
backend:
  environment:
    DB_CONNECTION: mysql
    DB_HOST: db          # コンテナ間はサービス名で解決
    DB_DATABASE: ebooks_local
    DB_TIMEZONE: "+09:00"
```

Laravel の Dotenv は immutable で、**既存の環境変数を `.env` の値で上書きしません**。そのため `.env` が `DB_CONNECTION=sqlite` のままでも、コンテナ内では compose の値が優先されて MySQL に繋がります。ホスト側から `artisan` を叩くときは `.env` が使われるので、`DB_HOST` の値を使い分けられます。

## 変更ファイル

| パス | 役割 |
|---|---|
| `compose.yaml` | `db` / `phpmyadmin` サービス、`db-data` ボリューム、`backend` への DB 接続情報 |
| `backend/database/migrations/..._create_books_table.php` | `books` テーブル |
| `backend/database/migrations/..._create_book_pages_table.php` | `book_pages` テーブル |
| `backend/app/Models/Book.php` | `SoftDeletes`、`pages()` リレーション |
| `backend/app/Models/BookPage.php` | `book()` リレーション |
| `backend/database/seeders/BookSeeder.php` | サンプル書籍 1 冊 + 10 ページ。`updateOrCreate` で冪等 |
| `backend/config/app.php` / `config/database.php` | タイムゾーンを `env()` 経由に |
| `backend/phpunit.xml` | テストが開発用 DB を見ないようにする指定 |

## テーブル設計

```
books                              book_pages
├ id                               ├ id
├ code          (URL 上の識別子)    ├ book_id   → books.id (ON DELETE CASCADE)
├ title                            ├ page_no
├ description                      ├ img_path  (CDN ルートからの相対パス)
├ pages_count   (非正規化した件数)  ├ created_at
├ published_at                     └ updated_at
├ is_active     (生成列)
├ deleted_at    (論理削除)
├ created_at
└ updated_at
```

`img_path` には `/books/sample/page-01.svg` のような相対パスだけを入れます。ホスト名は `config/cdn.php` が持っているので、CDN を実サービスに移しても DB は触らずに済みます（Step.02 で引いた線をそのまま持ち込んでいます）。

`published_at` には索引を張りました。「公開済みの本を新しい順に」が一覧の基本クエリになるためです。`book_pages` の `unique(book_id, page_no)` は重複ページを防ぐと同時に、ページ順の取得にも効きます。

### 論理削除と一意制約の両立

`code` は URL に出る識別子なので一意にしたいのですが、単純に `unique` を張ると**削除済みの本の `code` を再利用できなくなります**。かといって `deleted_at` を複合ユニークに含めると、NULL 同士は別物と判定されるため生存行の重複を一切弾けません。

そこで `deleted_at` から導出する生成列を置き、それと複合ユニークにしています。

```php
$table->unsignedTinyInteger('is_active')
    ->storedAs('CASE WHEN deleted_at IS NULL THEN 1 END');

$table->unique(['code', 'is_active']);
```

| 行の状態 | `is_active` | ユニーク判定 |
|---|---|---|
| 生存 | `1` | `(code, 1)` で比較され、重複を弾く |
| 削除済み | `NULL` | NULL を含む行は判定対象外なので、何件でも共存できる |

SQL の「NULL を含む行はユニーク制約の対象外」という性質を逆手に取った書き方です。値は DB が入れるのでアプリ側は何もせず、`SoftDeletes` をそのまま使えます（生成列に書き込むと DB エラーになるため、モデルの `fillable` には入れません）。

型は `tinyint` です。`enum('0','1')` は避けました。ENUM は内部的に 1 始まりのインデックスで値を持つため、`WHERE is_active = 1` のような数値比較がインデックス比較になり、**1 番目の要素である `'0'` にマッチします**。クォートを 1 つ忘れただけで静かに壊れる上、サイズもどちらも 1 バイトで差がありません。

### タイムスタンプに DB 既定値を持たせる

```php
$table->timestamp('created_at')->useCurrent();
$table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
```

```sql
`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
```

Laravel の `timestamps()` は nullable で既定値なしのカラムを作ります。Eloquent 経由なら PHP 側が値を入れるので困りませんが、管理画面のバッチや生 SQL で画像を差し替えたときに `updated_at` が動かないと、**Step.02 で組んだ `?v=` のキャッシュバスターが更新されません**。DB 側からもその約束を守らせています。

### 命名は Laravel の規約に寄せる

最初は `books_pages` / `books_id` としていましたが、規約（モデル名の複数形・参照先モデル名の単数形 + `_id`）に合わせて `book_pages` / `book_id` に変更しました。規約に乗ると、モデル側の明示指定が 3 箇所消えます。

```php
// 規約から外れていると
$table->foreignId('books_id')->constrained('books');   // 参照先テーブルの指定が必要
$this->hasMany(BookPage::class, 'books_id');           // 外部キーの指定が必要
#[Table('books_pages')]                                // テーブル名の指定が必要

// 規約どおりなら
$table->foreignId('book_id')->constrained();
$this->hasMany(BookPage::class);
```

`constrained()` は「カラム名から `_id` を落として複数形にしたテーブル」を参照先とみなします。`books_id` だと `bookses` を探しにいくため、明示指定が必須でした。

総ページ数のカラムも `pages` から **`pages_count`** に変えています。`pages` のままだとリレーション名 `pages()` と衝突し、**Eloquent はカラムを優先するため `$book->pages` がリレーションではなく `int` を返し続ける**という気づきにくいバグになります。`pages_count` は `withCount('pages')` が生成する属性名と同じなので、カラムから読んでも実数を数えても同じ名前で扱えます。

## モデルとシーダー

```php
#[Fillable(['code', 'title', 'description', 'pages_count', 'published_at'])]
class Book extends Model
{
    use SoftDeletes;

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class);
    }
}
```

`is_active` は `fillable` に入れていません。生成列なので、書き込もうとすると DB がエラーを返します。

シーダーは `updateOrCreate` で書いてあり、何度流しても同じ状態になります。

```php
$book = Book::updateOrCreate(['code' => 'sample'], [...]);

foreach (range(1, 10) as $pageNo) {
    $book->pages()->updateOrCreate(
        ['page_no' => $pageNo],
        ['img_path' => sprintf('/books/sample/page-%02d.svg', $pageNo)],
    );
}
```

`BookController` にベタ書きしていた 10 ページ分を、そのまま DB に移した内容です。次のステップで API がこれを読むようになります。

## タイムゾーン

日本時間に揃えるために、**3 箇所**の設定が必要でした。どれか 1 つでも抜けるとズレます。

| どこ | 設定 | 効く範囲 |
|---|---|---|
| MySQL サーバ | `command: --default-time-zone=+09:00` | `CURRENT_TIMESTAMP`、サーバ既定のタイムゾーン |
| Laravel アプリ | `APP_TIMEZONE=Asia/Tokyo`（`config/app.php` を `env()` 経由に変更） | PHP が生成する日時（`now()` など） |
| DB 接続 | `DB_TIMEZONE=+09:00`（`config/database.php` の `'timezone'`） | 接続ごとの `time_zone`。`timestamp` 型の解釈と変換 |

MySQL 側を `Asia/Tokyo` という名前ではなくオフセットで指定しているのは、名前を使うにはタイムゾーンテーブル（`mysql_tzinfo_to_sql`）の読み込みが必要で、未読込のまま起動すると失敗するためです。日本は DST が無いので実質同義です。

接続単位の `DB_TIMEZONE` が要るのは、`timestamp` 型がセッションのタイムゾーンで解釈・変換されるからです。サーバ側だけ JST にしても、接続が UTC のままなら `useCurrent()` で入る値がズレます。

```
シーダーが '2026-09-20 00:00:00' を指定 → DB に 2026-09-20 00:00:00 が入る
```

## テストが開発用 DB を見ないようにする

`phpunit.xml` には最初からインメモリ SQLite を使う指定があります。

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

ところが compose の `environment` で渡した値は、コンテナ内で**本物の環境変数として `$_SERVER` に入ります**。Laravel の `env()` は `$_SERVER` → `$_ENV` → `putenv` の順に参照するため、`$_ENV` しか書き換えない `<env>` では勝てません。このまま `RefreshDatabase` を使うテストを書くと、**開発用の `ebooks_local` に接続してテーブルを落とします**。

`$_SERVER` 側を上書きする `<server>` を併記して塞いであります。

```xml
<server name="APP_ENV" value="testing" force="true"/>
<server name="DB_CONNECTION" value="sqlite" force="true"/>
<server name="DB_DATABASE" value=":memory:" force="true"/>
```

## マイグレーションと phpMyAdmin

```bash
docker compose up -d
docker compose exec backend php artisan migrate --seed
docker compose exec backend php artisan migrate:status
```

| コマンド | 挙動 |
|---|---|
| `migrate:fresh --seed` | 全テーブルを DROP → 流し直し → シード。`down()` の出来に依存しない |
| `migrate:refresh` | `down()` で巻き戻してから流し直す。`down()` の検証も兼ねたいとき |
| `docker compose down -v` | `db-data` ボリュームごと破棄する |

phpMyAdmin は `PMA_USER` / `PMA_PASSWORD` を渡して自動ログインにしています。`ebooks` ユーザーでは `ebooks_local` しか見えないので、他の DB を触りたいときはこの 2 行を消すとログイン画面が出ます（`root` / `root`）。本番に持っていく構成ではないので、compose を環境別に分ける段階で `compose.override.yaml` 側へ移します。

## 動作確認

```bash
# テーブルと生成列の確認
docker compose exec db mysql -uebooks -ppassword -e 'SHOW CREATE TABLE books\G' ebooks_local

# シードが入ったか（日時が JST で入っているか）
docker compose exec db mysql -uebooks -ppassword -e 'SELECT code, pages_count, published_at FROM books;' ebooks_local
```

| URL | 内容 |
|---|---|
| http://localhost:8083 | phpMyAdmin |
| http://localhost:8000/api/books/sample | API（**まだベタ書きのデータを返します**） |

## ハマりどころ

| 症状 | 原因 |
|---|---|
| `migrate` がタイミングで失敗する | MySQL が接続を受け付ける前に実行している。`condition: service_healthy` で待つ |
| `--default-time-zone=Asia/Tokyo` で MySQL が起動しない | 名前指定にはタイムゾーンテーブルの読み込みが必要。オフセット `+09:00` を使う |
| サーバを JST にしたのに日時がズレる | 接続単位の `time_zone` が UTC のまま。`DB_TIMEZONE` が要る |
| `WHERE is_active = 1` が削除済み行にマッチする | カラムを `enum('0','1')` にした場合の典型。ENUM の数値比較はインデックス比較になる |
| テストが開発データを消す | compose の環境変数が `$_SERVER` に入り、`phpunit.xml` の `<env>` が効かない。`<server>` で上書きする |
| `$book->pages` が `int` を返す | 総ページ数カラムとリレーション名の衝突。カラムを `pages_count` に改名して回避 |

## 積み残し

- **API がまだ DB を見ていない**。`BookController::BOOKS` は定数のまま、ルートも `{slug}` のままです
- **テストが無い**。テスト用 DB の隔離は済ませたので、次のステップで書けます
- `artisan serve` のまま（Step.02 からの持ち越し）

## 次のステップ

- `BookController::BOOKS` を Eloquent に置き換え（ルートも `{slug}` → `{code}`）
- フィーチャテストの追加
- 書籍一覧 API と一覧ページ（`pages_count` と書影が要るのはここ）

🤖 Generated with [Claude Code](https://claude.com/claude-code)
