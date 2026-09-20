# feat(frontend): 電子書籍ビューア（ページめくり）の実装

バックエンドと切り離したフロントエンド単体として、画像ベースの電子書籍ビューアを実装しました。ビルドツールなしの Vanilla JS / nginx 配信です。

## 変更ファイル

| パス | 役割 |
|---|---|
| `docs/public/md/step01.md` | このドキュメント |
| `docs/public/artifact/step01.html` | 解説用アーティファクト |
| `docs/public/index.html` | docs ビューア。`fetch` + marked で md を描画し、artifact は iframe 表示 |
| `docs/nginx.conf` | docs 配信（`autoindex` でファイル一覧、`.md` を `text/plain` 扱い） |
| `frontend/public/index.html` | DOM の骨組み（レイヤ2枚・ツールバー・シークバー） |
| `frontend/public/css/style.css` | 見た目と**アニメーション本体**（`transition` は CSS 側） |
| `frontend/public/js/reader.js` | 状態管理と回転角の付け外し |
| `frontend/public/assets/pages/page-01..10.svg` | 動作確認用ダミーページ10枚 |
| `frontend/Dockerfile` / `frontend/nginx.conf` | 静的配信 |
| `tools/generate_dummy_pages.py` | ダミーページ生成スクリプト |
| `compose.yaml` | ローカル起動（`:8080`）。`backend` サービスの追加箇所をコメントで確保 |

公開されるのは `frontend/public/` 配下のみです。`Dockerfile` / `nginx.conf` / `tools/` はその外に置いてあるため Web からは参照されません。`compose.yaml` では `public/` を**1つだけ**バインドマウントしています（サブディレクトリを個別にマウントすると Docker Desktop 側でマウントが外れ、コンテナ内が空になることがあるため）。あわせて `sendfile off`（`server` コンテキスト）で、更新後に古い内容が配信される問題を回避しています。

## ロジック

### 単方向データフロー

```
入力 ──▶ state を更新 ──▶ render() ──▶ DOM
```

状態は3つだけ。派生値は持たず都度計算するため、同期ズレが起きません。

```js
const state = {
  current:    1,      // 現在ページ (1 始まり)
  direction:  'ltr',  // 綴じ方向。実行中に切り替え可能
  isFlipping: false,  // めくり中は操作を受け付けない
};
```

DOM を直接触るのは `render()` と `goTo()` / `finishFlip()` のみ。ページ画像の差し替えだけは、アニメーションのタイミングと不可分なため `goTo()` 側に置いています。

### レイヤ構造

重ねる `<img>` は **2枚だけ**。総ページ数が増えても DOM は増えません。

```
.book
├─ #pageUnder   ← 下地。常に表示されている
└─ #sheet       ← 綴じ目を軸に回転する「紙」
   ├─ #pageFlip ← 紙の表面に貼る画像
   └─ #sheetShade
```

静止時は下地と紙が**同じページ**を表示しています。これにより、めくり終わりに紙の角度を `-180deg` → `0deg` へ戻す瞬間にも下地に正しい絵があり、チラつきが構造的に発生しません。「紙を隠してから差し替える」といった順序制御やデコード完了待ちが不要になります。

### めくりの原理

綴じ目（`transform-origin`）を軸に紙を `rotateY(0deg → -180deg)` させ、`backface-visibility: hidden` により90度を超えた紙が自動的に消えて下地が現れます。**消すタイミングを JS で判定する必要がありません。**

進む／戻るは同じ仕組みで、分岐は2点のみです。

| | 下地 `#pageUnder` | 紙 `#pageFlip` | 開始角 | 終了角 |
|---|---|---|---|---|
| **進む** | 遷移先を敷く | 現在ページ（そのまま） | `0deg` | `-180deg` |
| **戻る** | 現在ページ（そのまま） | 遷移先を貼る | `-180deg` | `0deg` |

補間は CSS の `transition` が行い、JS は角度の値を入れ替えるだけです（`requestAnimationFrame` ループなし）。

### `goTo()` の手順

1. 絵を決める（進む＝下地に遷移先／戻る＝紙に遷移先）
2. `transition` を切った状態で開始角に置く
3. `forceReflow()` でレイアウトを確定させる
4. `transition` を入れて終了角へ。`animationMs` 後に `finishFlip()`

```js
el.sheet.classList.remove('is-animating');
el.sheet.style.transformOrigin = spineOrigin();
setSheetAngle(forward ? 0 : angle);

forceReflow(el.sheet);              // ← 3

el.sheet.classList.add('is-animating');
setSheetAngle(forward ? angle : 0);
```

**手順3が必須です。** ブラウザは同一フレーム内のスタイル変更をまとめて適用するため、これがないと開始角が無かったことにされ、`transition` が再生されません。`void node.offsetWidth` の読み取りがレイアウト計算を強制します。

後始末は `transitionend` ではなく `setTimeout` で予約しています。タブが非アクティブになると `transitionend` が発火せず、`isFlipping` が `true` のまま固まって操作不能になるためです。

### 綴じ方向

左綴じ／右綴じの分岐は3関数に閉じ込めてあります。

| 関数 | ltr | rtl | 効果 |
|---|---|---|---|
| `spineOrigin()` | `left center` | `right center` | 回転軸の位置 |
| `flipAngle()` | `-180` | `180` | 倒れる向き |
| `deltaForSide()` | 右＝次へ | 左＝次へ | タップ／矢印キーの対応 |

影の向きのみ CSS 側（`[data-direction="rtl"]`）で反転させています。

### 設定

`CONFIG` を変えるだけで大半の挙動を調整できます。バックエンド接続時は `init()` で書誌情報を取得し `CONFIG` を上書きする想定です。

| キー | 既定値 | 内容 |
|---|---|---|
| `totalPages` | `10` | 総ページ数 |
| `pageSrc(n)` | `assets/pages/page-NN.svg` | ページ番号 → 画像URL |
| `direction` | `'ltr'` | 綴じ方向 |
| `animationMs` | `600` | めくり時間。起動時に CSS 変数 `--flip-duration` へ反映 |
| `preloadRange` | `2` | 前後何ページを先読みするか |
| `swipeThreshold` | `50` | スワイプ判定の最小移動量(px) |
| `storageKey` | — | しおりの `localStorage` キー |

## 操作

左右タップ / `←` `→` / `PageUp` `PageDown` / `Space` / `Home` `End` / 横スワイプ / シークバー / 全画面（`F`）

## その他の実装

- 前後2ページのプリロード（`new Image()` でキャッシュに載せる）
- `localStorage` によるしおり復帰。読み書きは `try/catch` で保護（シークレットウィンドウ対策）
- `prefers-reduced-motion: reduce` で `--flip-duration` を `1ms` にし即時切り替え
- `aria-live="polite"` でページ番号を読み上げ
- デバッグ用に `window.reader` を公開（`reader.goTo(5)` など）

## 動作確認

- 全アセットが `200` で配信されること（HTML / CSS / JS / SVG / `/healthz`）
- `getElementById` 12箇所すべてが HTML 側に存在
- `CONFIG.animationMs`(600) と CSS `--flip-duration`(600ms) の一致
- 画像枚数(10) と `totalPages`(10) の一致

**未確認:** めくりアニメーションの見た目はブラウザでの目視確認ができていません。レビュー時に `docker compose up -d` → http://localhost:8080 でご確認ください。

## 今後

- `backend/` の追加と `pageSrc` の API 切り替え
- 見開き表示（レイヤ2枚構造と回転の仕組みはそのまま流用可能）
- しおりのサーバ保存（`saveProgress()` / `loadProgress()` の差し替えのみ）

🤖 Generated with [Claude Code](https://claude.com/claude-code)
