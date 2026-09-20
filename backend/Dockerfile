# ローカル開発用の API コンテナ。
# 本番に寄せる段階では php-fpm + nginx (または RoadRunner/Octane) に差し替える。
# ここでは artisan serve をそのまま使い、ソースは compose 側で bind mount する。
FROM php:8.4-cli-alpine

# pdo_mysql は DB を入れる段階で必要になるので先に入れておく
RUN docker-php-ext-install pdo_mysql

# 起動時の composer install 用 (公式イメージから composer だけ取り込む)。
# dist の展開に unzip、フォールバックの source 取得に git を使う
RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# bind mount で上書きされるが、単体でも動くようにソースを入れておく
COPY . /app

# 起動時に vendor / .env / APP_KEY が無ければ用意する。
# /app は bind mount で上書きされるので、スクリプトは外に置く
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

EXPOSE 8000

# 0.0.0.0 で待たないとコンテナ外から届かない
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
