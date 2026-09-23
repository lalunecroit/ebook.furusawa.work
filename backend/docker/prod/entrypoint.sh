#!/bin/sh
# 本番用 entrypoint。
#
# ソースも vendor もイメージに焼き込まれているので、ここでやるのは
# 「実行時の環境変数に依存するもの」だけに絞る。
# composer install や key:generate は開発用 entrypoint の仕事で、ここではやらない
# (APP_KEY を起動のたびに作ると暗号化済みデータが読めなくなる = step07 の 5)。
set -e

# APP_KEY は必ず外から渡す。本番では生成しない (step07 の 5)。
#
# 未設定でもコンテナは起動でき、DB を読むだけの API は 200 を返してしまう。
# 落ちるのは暗号化を使う経路 (セッション、管理画面のログイン) に来たときで、
# MissingAppKeyException が出るまで異常に気づけない。そのため起動時に止める。
#
# 値の供給元はデプロイ側の選択。Cloud Run なら
#   --set-secrets=APP_KEY=<secret名>:latest   (Secret Manager)
#   --set-env-vars=APP_KEY=base64:...         (平文。リビジョン設定に残る)
if [ -z "${APP_KEY}" ]; then
  echo "[entrypoint] APP_KEY が設定されていません。起動を中止します。" >&2
  echo "[entrypoint] 本番では鍵を生成せず、必ず外から渡してください。" >&2
  echo "[entrypoint]   例: gcloud run deploy ... --set-secrets=APP_KEY=app-key:latest" >&2
  exit 1
fi

# base64: 形式なら鍵長も見る。短い鍵は起動を通過して暗号化の時点で落ちるため。
# AES-256-CBC は 32 バイト、AES-128-CBC は 16 バイト。
case "${APP_KEY}" in
  base64:*)
    key_bytes=$(printf '%s' "${APP_KEY#base64:}" | base64 -d 2>/dev/null | wc -c | tr -d ' ')
    if [ "${key_bytes}" != "32" ] && [ "${key_bytes}" != "16" ]; then
      echo "[entrypoint] APP_KEY の鍵長が不正です (${key_bytes} バイト)。" >&2
      echo "[entrypoint] AES-256-CBC なら 32 バイト、AES-128-CBC なら 16 バイトが必要です。" >&2
      exit 1
    fi
    ;;
esac

# Cloud Run はリッスンすべきポートを $PORT で渡してくる (既定 8080)。
# nginx は設定ファイル内で環境変数を展開できないのでここで埋める。
# 置換対象を ${PORT} に限定しないと $uri などの nginx 変数まで消える。
export PORT="${PORT:-8080}"
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# nginx が使う一時ディレクトリ (テンプレートで /tmp 配下に寄せてある)
mkdir -p /tmp/nginx-client-body /tmp/nginx-proxy /tmp/nginx-fastcgi \
         /tmp/nginx-uwsgi /tmp/nginx-scgi

# 設定キャッシュはビルド時ではなく起動時に作る。
# ビルド時に config:cache すると、そのときの環境変数が焼き付いてしまい、
# Cloud Run が実行時に渡す DB_* や APP_KEY が反映されない。
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 上の artisan は root で走るので、php-fpm のワーカー (www-data) が
# 後から書けるように所有者を戻す
chown -R www-data:www-data storage bootstrap/cache

# マイグレーションはここでは流さない。インスタンスが同時に複数立つと競合するため、
# Cloud Run Jobs か手動実行に分ける (step07 の 10)。

exec "$@"
