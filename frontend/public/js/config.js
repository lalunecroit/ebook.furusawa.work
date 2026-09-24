/* ==========================================================================
 * 配信環境ごとの設定
 *
 * フロントは静的ファイルをそのまま配るだけで、ビルドもテンプレート展開も無い。
 * そのためデプロイ時に値を差し込むことができない。
 * ここでは「開いているページのホスト名」から接続先を決める。
 *
 * 環境ごとにファイルを差し替える方式にしなかった理由:
 *   本番用に書き換えるのを忘れると、公開サイトが localhost の API を見に行き、
 *   ページが空のまま何も出ない状態になる。しかもサーバ側は正常なので気づきにくい。
 *   アップロードするファイルを環境で変えなければ、その事故自体が起きない。
 *
 * 逆にステージング等を足すときは、この表に 1 行足すことになる。
 * ========================================================================== */

'use strict';

(() => {
  /** ホスト名 → API のベースURL */
  const API_BASE_BY_HOST = {
    localhost: 'http://localhost:8000/api',
    '127.0.0.1': 'http://localhost:8000/api',
    'www.ebook.furusawa.work': 'https://api.ebook.furusawa.work/api',
    'ebook.furusawa.work': 'https://api.ebook.furusawa.work/api',
  };

  const host = location.hostname;
  let apiBase = API_BASE_BY_HOST[host];

  if (!apiBase) {
    // 表に無いホストから開かれた場合。www. を api. に読み替える規則で組み立てる。
    // 黙って推測すると原因が追いにくいので警告を出す。
    apiBase = `${location.protocol}//api.${host.replace(/^www\./, '')}/api`;
    console.warn(`[config] 未知のホスト ${host} です。API のベースURLを ${apiBase} と推測しました。`);
  }

  /** 各スクリプトはここから読む。書き換えを防ぐため freeze する */
  window.APP_CONFIG = Object.freeze({ apiBase });
})();
