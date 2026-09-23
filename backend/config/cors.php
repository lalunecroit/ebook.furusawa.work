<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | サブドメインで役割を分けているので、www. から api. を fetch するのは
    | クロスオリジンになる (プロトコル・ホスト・ポートの3つが揃って初めて
    | 同一オリジン)。
    |
    | フレームワークの既定は allowed_origins => ['*'] で、どのサイトからでも
    | API を読めてしまう。読ませたいオリジンだけを明示する。
    |
    */

    /*
     * 管理画面 (admin.) は自分のページから自分のサーバを叩くだけなので
     * 同一オリジンであり、CORS は要らない。対象は公開 API に限る。
     * 既定の 'sanctum/csrf-cookie' も Sanctum を使っていないため外した。
     */
    'paths' => ['api/*'],

    /*
     * 公開 API は参照のみ (GET api/books, api/books/{book}, api/books/{book}/pages)。
     * プリフライトのために OPTIONS も許可する。
     */
    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    /*
     * 環境ごとに変わるのでカンマ区切りの環境変数で渡す。
     *   本番: CORS_ALLOWED_ORIGINS=https://www.ebook.furusawa.work,https://ebook.furusawa.work
     *   開発: http://localhost:8080 (compose の frontend)
     *
     * 空文字やスペースだけの要素は落とす。1つでも '*' が混ざると全開放に
     * なってしまうので、値は明示的に列挙すること。
     */
    'allowed_origins' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:8080')),
    ), static fn (string $origin): bool => $origin !== '')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    /*
     * プリフライト (OPTIONS) の結果をブラウザにキャッシュさせる秒数。
     * 既定の 0 だと毎回プリフライトが飛ぶ。
     */
    'max_age' => 3600,

    /*
     * Cookie は送らせない。公開 API は認証不要で、セッションを使う管理画面は
     * 同一オリジンのため。true にすると allowed_origins に '*' が使えなくなる。
     */
    'supports_credentials' => false,

];
