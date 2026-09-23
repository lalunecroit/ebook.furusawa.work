#!/bin/sh
# 本番用 entrypoint。
#
# ソースも vendor もイメージに焼き込まれているので、ここでやるのは
# 「実行時の環境変数に依存するもの」だけに絞る。
# composer install や key:generate は開発用 entrypoint の仕事で、ここではやらない
# (APP_KEY を起動のたびに作ると暗号化済みデータが読めなくなる = step07 の 5)。
set -e

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
