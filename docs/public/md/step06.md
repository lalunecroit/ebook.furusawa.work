# feat(admin): 書籍と書籍画像を登録・編集する管理画面

Step.05 までは、書籍の追加もページ画像の差し替えも **JSON カタログを書き換えてシーダーを流す** しか手段がありませんでした。
このステップでは backend に **管理画面（`/admin`）** を追加し、書誌情報の登録・編集と、ページ画像のドラッグ&ドロップでのアップロードをブラウザから行えるようにします。
あわせて、画像がまだ無い書籍を公開側で開いたときに画面が崩れる問題を直し、最後に管理画面へログイン認証を入れました。

| # | コミット | 内容 |
|---|---|---|
| 1 | backend: 管理画面の追加 | `/admin` のルート、書誌情報の一覧・登録・編集、テスト 19 本 |
| 2 | frontend: 画像未登録書籍の表示修正 | 一覧の「No Image」、ビューアの案内表示 |
| 3 | backend: 管理画面に書籍画像設定を追加 | ページ画像のアップロード・差し替え・削除、GD の追加、テスト 12 本 |
| 4 | backend: 画像パーミッションの修正 | 保存したファイルを nginx から読めるようにする |
| 5 | backend: 管理画面にログイン追加 | セッション認証、ログイン試行の制限、テスト 19 本 |

## 構成

コンテナは 8 つのままで、**このステップで増えたものはありません**。変わったのは `backend` の役割です。

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
| `backend-web` | 8000 | nginx | HTTP の受け口。静的ファイルを返し、PHP は php-fpm へ渡す |
| `backend` | （9000・内部のみ） | php-fpm + GD | 公開 API と**管理画面**。Laravel 本体 |
| `cdn` | 8082 | nginx | ページ画像。**管理画面からの書き込み先でもある** |
| `db` | 3306 | MySQL 8.4 | 書誌情報 |
| `phpmyadmin` | 8083 | phpMyAdmin | DB 管理画面 |
| `docs` | 8081 | nginx | このドキュメント |
| `tools` | — | php-cli + GD | サンプル画像の生成。`profiles` で通常の `up` からは外している |

**`backend` から `cdn` へ伸びている矢印が、このステップで増えた経路です。** Step.05 まで `cdn` に書き込むのは `tools` だけでしたが、管理画面からアップロードした画像も同じ場所に置くため、`cdn/public/` を `backend` にもマウントしています。`backend-web` を通る経路も、これまでの `/api/*` に加えて `/admin/*` が乗りました。

公開 API と管理画面が同じ `:8000` を共有している点は、本番でサブドメインを分ける段階（`api.` / `admin.`）で見直す予定です。

## 1. 管理画面の入口

画面は **backend に Blade で作りました**。frontend と同じく Vanilla JS + API で作る案もありましたが、フォーム送信・CSRF・バリデーションエラーの差し戻しを Laravel に任せられ、管理画面の一式を backend の中に閉じ込められる方を選びました。

ルートは `web.php` と分けて `routes/admin.php` に置き、`bootstrap/app.php` で束ねています。

```php
// bootstrap/app.php
->withRouting(
    ...
    then: function (): void {
        Route::middleware('web')
            ->prefix('admin')
            ->name('admin.')
            ->group(base_path('routes/admin.php'));
    },
)
```

`web` ミドルウェアを付けているのは、セッションと CSRF 保護を効かせるためです。`api` 側とはミドルウェアが違うので、ファイルごと分けておくと混ざりません。

| メソッド | パス | 名前 |
|---|---|---|
| `GET` / `POST` | `/admin/login` | `admin.login` / `admin.login.store` |
| `POST` | `/admin/logout` | `admin.logout` |
| `GET` | `/admin` | `admin.home`（一覧へリダイレクト） |
| `GET` | `/admin/books` | `admin.books.index` |
| `GET` | `/admin/books/create` | `admin.books.create` |
| `POST` | `/admin/books` | `admin.books.store` |
| `GET` | `/admin/books/{book}/edit` | `admin.books.edit` |
| `PUT` | `/admin/books/{book}` | `admin.books.update` |
| `GET` | `/admin/books/{book}/pages` | `admin.books.pages.index` |
| `POST` | `/admin/books/{book}/pages` | `admin.books.pages.store` |
| `DELETE` | `/admin/books/{book}/pages/{page}` | `admin.books.pages.destroy` |

`{book}` は Step.04 で付けた `#[RouteKey('code')]` がそのまま効くので、URL は `/admin/books/laravel-api/edit` のように読める形になります。

認証は §7 で入れました。ログイン画面だけは未ログインで開ける必要があるため、`auth` はグループ全体ではなく `routes/admin.php` の中で機能ごとに付けています。

## 2. 書誌情報の登録・編集

### コントローラは2行

```php
public function store(BookRequest $request): RedirectResponse
{
    $book = Book::create($request->validated());
    ...
}

public function update(BookRequest $request, Book $book): RedirectResponse
{
    $book->update($request->validated());
    ...
}
```

INSERT / UPDATE の SQL は Eloquent（`Illuminate\Database\Eloquent\Model`）が組み立てます。`Book` モデルに書いてあるのは設定だけで、それが次の場所で効いています。

| モデルに書いたもの | 効き方 |
|---|---|
| `#[Fillable([...])]` | リストに無いキーは黙って捨てる。フォームに `is_active`（生成列）を仕込まれても書き込まれない |
| `casts()` の `published_at` | フォームの `2026-09-22T10:00` を DB 形式へ変換 |
| `use SoftDeletes` | 一覧・編集で削除済みを除外 |

実際に発行される SQL を見ると、UPDATE は**変わった列だけ**になっています。

```sql
insert into `books` (`code`, `title`, `description`, `published_at`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?, ?)
update `books` set `title` = ?, `books`.`updated_at` = ? where `id` = ?
```

### 一覧は未公開も含めて全件

公開側の API は `published()` スコープで公開済みだけに絞っていますが、管理画面では使いません。状態はバッジで区別します。

| `published_at` | バッジ |
|---|---|
| `null` | 未公開 |
| 未来日時 | 公開予約 |
| 過去日時 | 公開中 |

### 書籍コードの一意性はスキーマと同じ考え方で

Step.03 で「生存行の `code` は一意、削除済みとは重複してよい」を `(code, is_active)` の複合ユニークで表現しました。フォームのバリデーションも同じ意味にそろえています。

```php
'code' => [
    'required', 'string', 'max:64',
    'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
    Rule::unique('books', 'code')
        ->whereNull('deleted_at')    // 削除済みとは重複してよい
        ->ignore($book),             // 編集中の自分自身は除く
],
```

DB 側の制約だけに頼ると、重複時に 500 エラーになってしまいます。フォームで先に弾いて、入力を保ったまま差し戻すためにバリデーションにも同じ規則を書いています。

## 3. 画像がまだ無い書籍

書誌情報だけ先に登録して画像を後から入れる、という運用が可能になったので、**ページが0枚の書籍**が公開側に出てくるようになりました。実際に作って確かめると、API はすべて 200 で正常に応答する一方、画面側に2つの問題がありました。

### 一覧: 表紙が無いとカードの高さが崩れる

表紙が無い書籍はカードから `<img>` ごと抜けていたため、他のカードより背が低くなっていました。**「No Image」のプレースホルダ画像**を用意し、常に `<img>` を出すようにしています。

```js
img.src = book.cover?.url ?? CONFIG.noImagePath;   // 'assets/no-image.svg'
```

プレースホルダはページ画像と同じ **800×1131** の SVG にしたので、`aspect-ratio: 800 / 1131` の枠に収まり、全カードの高さがそろいます（実測で全件 517px）。生成プログラムは残さず、SVG を直接置いています。

### ビューア: 白紙のまま操作できない

`totalPages = 0` で起動すると、画像は `src=""`、ナビは両方無効、終端モーダルも出ない、という状態になっていました。**エラーも出ないので、利用者からは「ただ壊れている」ようにしか見えません。**

起動時に0ページなら案内を出して終了するようにしました。

```js
if (CONFIG.totalPages === 0) {
  showEmpty();   // 「この書籍にはまだページがありません。」+ 一覧へ戻るリンク
  return;
}
```

API 取得失敗時の表示（従来の `.fatal`）と共通化して `.notice` にまとめ、2種類に分けています。

| クラス | 用途 | 色 |
|---|---|---|
| `.notice--fatal` | API 取得失敗（異常） | 赤系 |
| `.notice--notice` | ページ未登録（運用途中の正常な状態） | グレー |

どちらにも一覧へ戻るリンクを付けています。Step.05 の最終ページの件と同じく、**行き止まりに導線を置かない**という方針です。

## 4. ページ画像のアップロード

### 書き込み先: `cdn` ディスク

画像の実体は、`cdn` サービスが配信している `cdn/public/` に置きます。これまで backend は自分のディレクトリしか見えていなかったので、compose でマウントを渡しました。

```yaml
backend:
  volumes:
    - ./backend:/app
    - ./cdn/public:/var/www/cdn
```

コードからは Laravel の Storage を通して扱います。

```php
// config/filesystems.php
'cdn' => [
    'driver' => 'local',
    'root' => env('CDN_ROOT', '/var/www/cdn'),
    'throw' => true,
],
```

`file_put_contents()` を直接書かないのは、**本番で GCS に移すとき `driver` を差し替えるだけで済ませるため**です。コントローラは変わりません。

### 保存の流れ

追加と差し替えは同じ `POST` にまとめ、`page_no` を送るかどうかで分けています。

```php
// 1. ページ番号を決める (省略なら末尾)
$pageNo = (int) ($request->input('page_no') ?: $book->pages()->max('page_no') + 1);
$existing = $book->pages()->firstWhere('page_no', $pageNo);

// 2. 差し替えなら古いファイルを先に消す (拡張子が変わることがあるため)
if ($existing !== null) {
    Storage::disk('cdn')->delete($this->relative($existing->img_path));
}

// 3. 保存
$path = sprintf('/books/%s/page-%02d.%s', $book->code, $pageNo, $file->extension());
Storage::disk('cdn')->putFileAs(dirname($relative), $file, basename($relative));

// 4. DB を更新して touch()
$page = $book->pages()->updateOrCreate(['page_no' => $pageNo], ['img_path' => $path]);
$page->touch();

// 5. 非正規化カラムを実数に合わせる
$book->update(['pages_count' => $book->pages()->count()]);
```

レスポンスは新規なら **201**、差し替えなら **200** です。

### `touch()` がこの機能の要

同じ拡張子で差し替えると、`img_path` は変わりません。Eloquent は「変更なし」と判断して UPDATE を発行せず、`updated_at` も動きません。

ところが画像 URL のキャッシュバスターは `?v=<updated_at>` で、CDN は Step.02 から「1年 + immutable」でキャッシュさせています。つまり `touch()` が無いと、

```
差し替え前  …/page-01.png?v=1790052491
差し替え後  …/page-01.png?v=1790052491   ← 同じ URL。ブラウザは取りに来ない
```

となり、**管理画面で差し替えても読者には古い画像が出続けます**。`touch()` で `updated_at` を進めると URL が変わり、差し替えが届きます。

```
差し替え後  …/page-01.png?v=1790052503
```

Step.02 のキャッシュ戦略、Step.03 の `ON UPDATE CURRENT_TIMESTAMP` と同じ約束を、管理画面の書き込み経路でも守っている形です。

### パスの先頭スラッシュ

既存の `book_pages.img_path` は `/books/sample/page-01.svg` と**先頭スラッシュ付き**で、公開 API はこれに CDN のベース URL をそのまま連結しています。一方 Storage のパスはディスクのルートからの相対です。そこで、

- DB には先頭スラッシュ付き（既存データとそろえる）
- ディスク操作のときだけ `ltrim($path, '/')` する

と役割を分けています。最初はスラッシュ無しで保存しかけており、そのままだと URL が `http://localhost:8082books/…` になるところでした。

### 削除は所有者を確認する

```php
public function destroy(Book $book, BookPage $page): JsonResponse
{
    abort_if($page->book_id !== $book->id, 404);
    ...
```

`{page}` は ID で引かれるので、URL の書籍部分を差し替えると**別の書籍のページを消せてしまいます**。組み合わせが合わなければ 404 にしています。

### 保存したファイルの権限

最初の実装では、アップロードした画像が **nginx から 403 になる**ことがありました。`Storage` の `local` ドライバは既定で `private` として書き込むため、ディレクトリが `0700`・ファイルが `0600` になり、**書いた PHP と配信する nginx が別ユーザーだと読めません**。

```php
'cdn' => [
    'driver' => 'local',
    'root' => env('CDN_ROOT', '/var/www/cdn'),
    'throw' => true,
    'visibility' => 'public',              // ファイルを 0644 に
    'directory_visibility' => 'public',    // ディレクトリを 0755 に
],
```

「公開ディレクトリに置くのだから読めて当然」と考えたくなりますが、**書き込み側の既定は非公開**です。GCS に移す段階でも同じ話が出てきます（オブジェクトの ACL / 公開設定）。

## 5. ドラッグ&ドロップ

画面は Blade の中に素の JS を書いています。

- 上部の大きな領域 → **末尾に追加**
- 各ページのサムネイル → **そのページを差し替え**
- クリックでもファイル選択できる（ドラッグできない環境向け）
- 複数落とされたら先頭1枚だけを処理し、その旨を表示

```js
node.addEventListener('dragover', (ev) => {
  ev.preventDefault();    // これが無いと drop が発火せず、ブラウザが画像を開いてしまう
  node.classList.add('is-over');
});
```

送信は `FormData` です。

```js
const body = new FormData();
body.append('image', file);
if (pageNo) body.append('page_no', pageNo);

await fetch(uploadUrl, {
  method: 'POST',
  headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
  body,
});
```

`Content-Type` はあえて指定していません。`FormData` を渡すとブラウザが `multipart/form-data` の境界文字列ごと組み立てるので、**自分で書くと境界が欠けて壊れます**。

`Accept: application/json` を付けているので、バリデーション失敗時は Laravel がリダイレクトではなく **422 + JSON** を返し、画面はそのメッセージをそのまま表示します。

### バリデーション

```php
'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:8192'],
'page_no' => ['nullable', 'integer', 'min:1', 'max:999'],
```

`mimes` はファイル名ではなく**中身から判定した MIME** で検査します。保存時の拡張子も `$file->extension()`（中身から推定）を使い、アップロード時のファイル名は信用していません。

## 6. backend に GD を入れる

画像のリサイズや検証を backend で行えるよう、`tools` コンテナと同じ手順で GD を入れました。

```dockerfile
RUN apk add --no-cache libjpeg-turbo libpng libwebp freetype \
    && apk add --no-cache --virtual .build-deps \
        libjpeg-turbo-dev libpng-dev libwebp-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd \
    && apk del .build-deps
```

イメージは +10MB（実測）です。管理画面と API を別のコンテナに分けるかも検討しましたが、この程度の差ではイメージを2本に分ける管理コストの方が大きいので、同じイメージのままにしています。

テストでも効いています。`UploadedFile::fake()->image()` は GD で本物の画像を生成するため、**MIME 判定まで実際のバイト列で検証できる**ようになりました（GD が無い間はダミーファイルで代用していました）。

## 7. ログイン認証

最後に、誰でも開けた `/admin` にログインを入れました。Laravel 標準のセッション認証（`web` ガード + 既存の `users` テーブル）です。Breeze は Vite / npm を持ち込むので使わず、必要な分だけ自前で書いています。

### auth はグループ全体ではなく機能ごとに付ける

§1 では「`then:` に `->middleware('auth')` を1行足すだけ」と書きましたが、実際にはそうできませんでした。**ログイン画面自体は未ログインで開けなければならない**ためです。`routes/admin.php` の中で分けています。

```php
// 未ログイン専用
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

// ここから下はログインが必要
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    ...
});
```

行き先は `bootstrap/app.php` で固定しました。セッション認証を使うのは管理画面だけなので、迷う余地がありません。

```php
$middleware->redirectGuestsTo(fn () => route('admin.login'));
$middleware->redirectUsersTo(fn () => route('admin.books.index'));
```

### 入れた安全策

| 対策 | 内容 |
|---|---|
| ログイン試行の制限 | メールアドレス + IP 単位で 5 回失敗するとロック。ロック中は正しいパスワードでも入れない |
| エラー文言を区別しない | 「パスワード違い」も「存在しないメールアドレス」も同じ文言。登録済みアドレスを探られない |
| セッション固定攻撃の対策 | ログイン時に `session()->regenerate()` で ID を作り直す |
| ログアウトは POST のみ | GET だと `<img src="/admin/logout">` で他サイトから強制ログアウトさせられる |
| ログアウト時の破棄 | セッション無効化 + CSRF トークン再生成 |
| intended リダイレクト | ログイン後、開こうとしていたページに戻る |

```php
public function store(LoginRequest $request): RedirectResponse
{
    $request->authenticate();

    // ログイン前のセッション ID を引き継がない
    $request->session()->regenerate();

    return redirect()->intended(route('admin.books.index'));
}
```

失敗時の文言を揃えているのは、**「このメールアドレスは登録されていません」と返すと、登録済みのアドレスを総当たりで特定できてしまう**ためです。

```php
throw ValidationException::withMessages([
    'email' => 'メールアドレスまたはパスワードが正しくありません。',
]);
```

### 管理者は artisan コマンドで作る

登録画面は用意していません。誰でも管理者になれてしまうためです。

```bash
docker compose exec backend php artisan admin:user admin@example.com --name=管理者
# → パスワードを対話式で入力（8文字以上）
```

パスワードを引数で受け取らないのは、**シェルの履歴に残る**ためです。`User` の `casts()` で `password => 'hashed'` になっているので、コマンド側でハッシュ化は書いていません。

## 8. テスト

管理画面に 50 本を追加しました。

| クラス | 本数 | 主な内容 |
|---|---|---|
| `Admin\BookTest` | 19 | 一覧（未公開も出る・削除済みは出ない）、登録、コード重複（生存行のみ）、fillable 外の無視、更新、404 |
| `Admin\BookPageTest` | 12 | 末尾追加、差し替え、**キャッシュバスターの更新**、拡張子変更時の旧ファイル削除、422、削除、所有者チェック |
| `Admin\AuthTest` | 15 | 未ログイン時のリダイレクトと 401、ログイン成功・失敗、intended、セッション ID の再生成、試行回数の制限、ログアウト |
| `Console\CreateAdminUserTest` | 4 | 作成、名前の省略、短いパスワード、重複メール。**平文で保存されていないこと**も確認 |

認証を入れたことで、`BookTest` と `BookPageTest` は `setUp()` に `actingAs()` を足しました。認証そのものの検証は `AuthTest` に集約しています。

```php
protected function setUp(): void
{
    parent::setUp();
    Storage::fake('cdn');   // 実際の cdn/public/ を汚さない
}
```

キャッシュバスターのテストは時間を進めています。`updated_at` は秒単位なので、同じ秒の中で2回アップロードすると `touch()` が効いていても URL が一致してしまうためです。

```php
$first = ...->json('page.url');
$this->travel(2)->seconds();
$second = ...->json('page.url');
$this->assertNotSame($first, $second);
```

書いたテストが実際に回帰を捕まえるかも確認しました。

| わざと壊した箇所 | 検出したテスト |
|---|---|
| `BookRequest` から `->whereNull('deleted_at')` を削除 | 削除済みの書籍とはコードが重複してよい |
| 管理画面の一覧に `published()` を付ける | 一覧には未公開と公開予約も出る |

認証まわりは「入れ忘れると誰でも触れてしまう」箇所なので、画面ごと（一覧・登録フォーム・編集・ページ画像）にリダイレクトを確認し、`fetch` から叩く経路は **401** が返ることも別に検証しています。

CSRF はテスト実行中はフレームワークが検証をスキップするので、ここでは扱っていません。実際の防御は、トークン無しの POST が **419** になることを別途確認しています。

## 動作確認

| 確認 | 結果 |
|---|---|
| 管理画面の一覧 | 10冊表示、状態バッジ、編集・画像へのリンク |
| 書籍の新規登録 | 302 → 編集画面、フラッシュ表示、DB に保存 |
| 更新 | 変わった列だけの UPDATE。公開日時を空にすると未公開に戻る |
| バリデーション | 重複・形式・必須漏れを検出し、入力を保って差し戻し |
| CSRF なしの POST | **419** |
| 画像のアップロード | 201、`cdn/public/books/…` に保存、CDN から配信、公開 API に即反映 |
| 差し替え | `?v=` が更新される |
| 拡張子の変わる差し替え | 古いファイルが消える |
| 画像以外 | 422 |
| ブラウザでの D&D | `dragover` でハイライト、`drop` でアップロード |
| 表紙の無い書籍（一覧） | 「No Image」表示、カードの高さがそろう |
| ページの無い書籍（ビューア） | 案内と一覧へのリンクを表示 |
| 未ログインで管理画面 | すべて 302 → `/admin/login`。`fetch` からは 401 |
| ログイン | 開こうとしていたページへ戻る。セッション ID が変わる |
| 6 回目のログイン試行 | 「試行回数が多すぎます。59 秒後に…」 |
| `GET /admin/logout` | **405** |
| アップロードした画像の権限 | ディレクトリ 0755 / ファイル 0644 |
| テスト | **65 passed / 222 assertions**（sqlite・MySQL とも） |

## ハマりどころ

| 症状 | 原因 |
|---|---|
| 画像を差し替えても読者に古い画像が出る | 同じパスへの上書きでは `updated_at` が動かず `?v=` が変わらない。`touch()` で進める |
| 差し替えで古い画像ファイルが残る | 拡張子が変わるとファイル名も変わる。保存前に古いファイルを消す |
| 画像 URL が `http://localhost:8082books/…` になる | DB は先頭スラッシュ付き、Storage はルート相対。境界で変換する |
| ドロップしてもブラウザが画像を開くだけ | `dragover` で `preventDefault()` していない |
| `fetch` のアップロードがサーバで壊れる | `FormData` に対して `Content-Type` を手で指定した。ブラウザに任せる |
| `UploadedFile::fake()->image()` で `GD extension is not installed` | backend に GD が無かった。追加して解消 |
| ページ0枚の書籍がビューアで白紙のまま | 起動時に `totalPages === 0` を判定していなかった |
| アップロードした画像が nginx から 403 | `local` ドライバの既定は `private`（0700 / 0600）。`visibility` を `public` にする |
| `auth` をグループ全体に付けるとログインできない | ログイン画面自体が `auth` で保護され、無限にリダイレクトする。`guest` と `auth` を分ける |
| テストのメソッド名が `_i_d` のように崩れる | Pint の `php_unit_method_casing` が大文字を分割する。英字は小文字で書く |

## 積み残し

- **`users` テーブルの全員が管理者**。読者向けのアカウントを作る段階では、権限の列か別ガードが要る
- **ファイル保存と DB 更新が非原子的**。古いファイル削除 → 新ファイル保存 → DB 更新の途中で失敗すると、画像が失われうる。新ファイルを別名で保存し、DB 確定後に古いファイルを消す順序が定石
- **同時アップロードの競合**。2人が同時に末尾へ追加すると同じ `max + 1` を計算し、片方が一意制約違反で 500 になる。`lockForUpdate()` 付きのトランザクションで番号を採る
- ページ番号の振り直しが無い（途中を削除すると番号が飛ぶ）
- SVG を受け入れている。スクリプトを埋め込める形式なので、不特定多数が使う運用になる前に見直す
- バリデーションメッセージが英日混在（`The 書籍コード has already been taken.`）。`lang/ja` の用意で解消する
- ページの無い書籍を公開一覧 API から除外する処理は未実装（現状は公開日時を空にしておく運用で回避）

## 次のステップ

- 管理画面を `admin.ebook.furusawa.work` として分離（同一イメージ・別 Cloud Run サービス）
- アップロード処理の原子性と同時実行対策
- 画像のリサイズ・サムネイル生成

🤖 Generated with [Claude Code](https://claude.com/claude-code)
