# feat(frontend): 書籍一覧ページを作り、複数冊を並べる

Step.04 まではサンプル書籍 1 冊（`sample`）だけで動作確認をしていました。
このステップでは **サンプルを 10 冊に増やし、一覧 API とトップページを追加**します。
`index.html` は「ビューアを直接開くページ」から「書籍一覧ページ」に役割が変わり、ビューア本体は `reader.html` に退きました。

| # | コミット | 内容 |
|---|---|---|
| 1 | tools: 書籍サンプル作成コンテナ追加 | 生成スクリプトを PHP に移植し、`tools` コンテナに載せる |
| 2 | backend: 追加書籍のSeed対応 | 書誌情報を JSON カタログに切り出し、10 冊分シードする |
| 3 | backend: 書籍一覧APIの作成 | `GET /api/books`。ページング・公開判定・表紙 |
| 4 | frontend: 書籍一覧ページ＆遷移作成 | `index.html` を一覧に、`reader.html` へ分離、終端の確認モーダル |

## 1. サンプル生成を PHP に移植し、tools コンテナへ

これまで `tools/generate_dummy_pages.py`（Python）でページ画像を作っていました。バックエンドが PHP なので、**言語を揃えて `tools/bin/generate-sample-pages.php` に書き直し**、専用の `tools` コンテナから実行できるようにしました。

```yaml
# compose.yaml
tools:
  build: ./tools
  profiles: ["tools"]      # 通常の up では起動しない
  volumes:
    - .:/work
```

```bash
docker compose run --rm tools                                   # 全冊生成
docker compose run --rm tools php tools/bin/generate-sample-pages.php --only=sample
```

`profiles` を付けているのは、この処理が**常駐するサービスではなく CLI ツール**だからです。`docker compose up -d` の対象から外れ、必要なときだけ `run` で起動します。

Python 版との出力差分は無く、既存の `sample` 10 枚はバイト単位で一致することを確認済みです（移植そのものは前段の作業で完了しており、このステップでは「複数冊に対応させる」形で作り直しています）。

## 2. 書誌情報を JSON カタログに切り出す

画像生成スクリプトと DB シーダーが**同じ書誌情報を別々に持つと、いずれ食い違います**。そこで1つの JSON にまとめ、両方がそれを読む形にしました。

```
backend/database/seeders/data/sample-books.json
        ├─▶ tools/bin/generate-sample-pages.php   (画像を作る)
        └─▶ backend/database/seeders/BookSeeder.php (DB に入れる)
```

```json
{
  "code": "compose-basics",
  "title": "compose で組むローカル環境",
  "description": "サービスの分け方、依存の待ち合わせ、ボリュームの扱いまで。",
  "published_at": "2026-08-05 00:00:00",
  "cover": { "line1": "compose で組む", "line2": "ローカル環境", ... },
  "chapters": [
    { "title": "サービスの切り分け", "type": "chapter" },
    { "title": "依存と起動順序", "type": "figure" },
    ...
  ]
}
```

10 冊分を用意し、公開日をわざとばらしました（2026-01 〜 2026-09）。一覧 API の並び順を確認するためです。

```php
// BookSeeder
foreach ($this->catalog() as $data) {
    $book = Book::updateOrCreate(['code' => $data['code']], [...]);

    foreach (range(1, self::PAGES_PER_BOOK) as $pageNo) {
        $book->pages()->updateOrCreate(
            ['page_no' => $pageNo],
            ['img_path' => sprintf('/books/%s/page-%02d.svg', $data['code'], $pageNo)],
        );
    }
}
```

`updateOrCreate` なので、何度流しても同じ状態になります。

## 3. 書籍一覧 API

```
GET /api/books?per_page=20&page=1
```

```json
{
  "data": [
    {
      "code": "sample",
      "title": "Docker で作る電子書籍サービス",
      "published_at": "2026-09-20T00:00:00+09:00",
      "total_pages": 10,
      "cover": { "page_no": 1, "img_path": "...", "url": "http://localhost:8082/books/sample/page-01.svg?v=..." }
    }
  ],
  "links": { "first": "...", "next": "..." },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 10 }
}
```

設計判断は3点です。

**ページ一覧を含めない。** 詳細（`BookResource`）は本文10ページ分の URL を全部返しますが、一覧で同じことをすると 20 冊で 200 件になります。代わりに表紙だけを返す `BookListResource` を新設しました。

```php
public function cover(): HasOne
{
    return $this->hasOne(BookPage::class)->where('page_no', 1);
}
```

**`total_pages` は `pages_count` カラムをそのまま使う。** 詳細エンドポイントは実際の行数を数えていますが（差し替え直後のズレを避けるため、Step.04 参照）、一覧で1冊ごとに `COUNT` すると本数分クエリが増えます。`pages_count` はまさにこの非正規化のために持たせたカラムです。

**公開判定は scope に切り出す。**

```php
public function scopePublished(Builder $query): Builder
{
    return $query->whereNotNull('published_at')
        ->where('published_at', '<=', now());
}
```

`published_at` が null（未公開）または未来日時（公開予約）の本は一覧に出しません。Step.03 で `published_at` に張った索引がここで効きます。

```php
$books = Book::published()
    ->with('cover')
    ->orderByDesc('published_at')
    ->orderByDesc('id')          // 公開日時が同着でも並びが安定する
    ->paginate($perPage)
    ->withQueryString();
```

発行されるクエリは3本（件数・本体・表紙の一括取得）で、N+1 は起きません。

## 4. フロントエンド: 一覧とビューアを分ける

```
変更前  index.html = ビューア本体

変更後  index.html = 書籍一覧          (新規)
        reader.html = ビューア本体      (index.html から改名)
```

一覧側 (`js/library.js`) は `/api/books` を取得してカードを並べるだけの薄い作りです。カードのリンク先は `reader.html?book=<code>`。

```js
const url = `${CONFIG.api.base}/books?per_page=${CONFIG.perPage}&page=${page}`;
```

ビューア側 (`reader.js`) は URL のクエリから開く本を決めるようにしました。

```js
const bookCode = new URLSearchParams(location.search).get('book') || 'sample';

const CONFIG = {
  api: { base: 'http://localhost:8000/api', code: bookCode },
  storageKey: `ebook:last-page:${bookCode}`,   // しおりも本ごとに分ける
  ...
};
```

`?book=` が無ければ `sample` にフォールバックするので、直接 `reader.html` を開いた場合の挙動は変えていません。

### ヘッダーに戻るリンク

```html
<a class="bar__back" href="index.html" title="書籍一覧へ戻る">← 一覧</a>
```

### 最終ページの終端マーク

最初は「最終ページで進む方向のナビボタンを無効化し、クリックしたら確認ダイアログを出す」という形にしましたが、**無効化したボタンは `pointer-events: none` でクリックを受け付けず、ダイアログが開かない**という不具合になりました。行き止まりでボタンごと反応が消えるのは、そもそも使い勝手としても良くありません。

そこで方針を変え、**最終ページの「進む」側は押せる状態のまま、記号を終端マーク（`⇥` / 右綴じなら `⇤`）に変える**形にしました。

```js
function updateNav(btn, side) {
  const delta = deltaForSide(side);
  const atEnd = delta > 0 && state.current === CONFIG.totalPages;

  btn.disabled = !atEnd && !isReachable(state.current + delta);
  btn.dataset.end = atEnd ? 'true' : 'false';

  const arrow = btn.querySelector('.nav__arrow');
  arrow.textContent = atEnd ? (side === 'left' ? '⇤' : '⇥') : (side === 'left' ? '‹' : '›');
}
```

クリックすると `<dialog>` の `showModal()` で確認を出します。

```js
function move(delta) {
  const target = state.current + delta;

  if (delta > 0 && state.current === CONFIG.totalPages) {
    openEndDialog();
    return;
  }
  if (!isReachable(target)) return;
  goTo(target);
}
```

ナビボタン・キーボード（→ / PageDown / Space）・スワイプはすべて `move()` を経由するため、**入力手段ごとに分岐を書く必要がありません**。`<dialog>` の `showModal()` を使うことで、フォーカスの閉じ込めと Esc での終了はブラウザに任せています。

## 動作確認

```bash
docker compose run --rm tools                                  # 10冊分の画像を生成
docker compose exec backend php artisan migrate:fresh --seed   # 10冊分をDBへ
```

| 確認 | 結果 |
|---|---|
| `GET /api/books` | 10冊、`published_at` の新しい順 |
| 発行クエリ | 3本（COUNT + 本体 + 表紙の一括取得）。N+1 なし |
| ページング | `?per_page=3` で `last_page: 4`、`links.next` も正しい |
| バリデーション | `per_page=0` / `101` / `abc` はいずれも 422 |
| 未公開・公開予約・削除済み | 一覧に出ない（テストで確認） |
| 一覧のカード | 10枚。リンク先は `reader.html?book=<code>` |
| `reader.html?book=laravel-api` | 該当する書誌情報・画像・ページ数で表示 |
| `reader.html`（パラメータなし） | 従来どおり `sample` にフォールバック |
| 最終ページのナビ | 記号が終端マークに変化。クリックでモーダルが開く |
| モーダルのキャンセル | 閉じるだけでページ位置は維持 |
| モーダルの「一覧に戻る」 | `index.html` へ遷移 |
| 途中ページ・先頭ページ | 従来どおり反応（モーダルは出ない） |
| 新規テスト | 7 本（`BookIndexTest`）追加、全体で 15 passed |

## ハマりどころ

| 症状 | 原因 |
|---|---|
| 最終ページでナビをクリックしても何も起きない | `disabled` にした要素は `pointer-events: none` で押せない。無効化ではなく見た目を変える方針にした |
| 一覧で1冊ごとにクエリが増える | `total_pages` を実数で数えると本数分 COUNT が走る。一覧は `pages_count` カラムをそのまま使う |
| ページ送りの途中で順序が揺れる | 公開日時が同着の本がある。`orderByDesc('id')` を副次キーに足して安定させる |

## 積み残し

- 一覧・詳細ともに検索や章立てでの絞り込みは無い
- 書影のリサイズ・最適化は未対応（`tools` コンテナに GD を入れてあるので、次はここが使い道）
- しおりのサーバ保存（`localStorage` のまま）

## 次のステップ

- 認証（`users` テーブルは雛形のまま残してある）
- しおりのサーバ保存
- 書影のサムネイル生成

🤖 Generated with [Claude Code](https://claude.com/claude-code)
