#!/bin/sh
# 初回起動に必要なセットアップを冪等に行ってから CMD を実行する。
# vendor / .env / APP_KEY はどれもホスト側 (bind mount) に作られる。
set -e

cd /app

if [ ! -f vendor/autoload.php ]; then
  echo "[entrypoint] vendor が無いので composer install を実行します"
  composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
  echo "[entrypoint] .env が無いので .env.example からコピーします"
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=.\+' .env; then
  echo "[entrypoint] APP_KEY を生成します"
  php artisan key:generate --force
fi

# SESSION_DRIVER=database のため、sessions テーブルが無いと最初の 1 リクエストから 500 になる。
# migrate は適用済みなら何もしないので毎回流す (開発用。本番は Cloud Run Jobs で単発実行)
echo "[entrypoint] マイグレーションを実行します"
php artisan migrate --force

exec "$@"
