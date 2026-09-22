# API コンテナ (php-fpm)。
# HTTP は前段の nginx (backend-web サービス) が受け、PHP の実行だけを担当する。
# artisan serve は開発用のシングルプロセスサーバなので使わない。
FROM php:8.4-fpm-alpine

# pdo_mysql は DB 接続に必要
RUN docker-php-ext-install pdo_mysql

# 起動時の composer install 用 (公式イメージから composer だけ取り込む)。
# dist の展開に unzip、フォールバックの source 取得に git を使う
RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# clear_env = no など、このアプリ向けの php-fpm 設定
COPY php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /app

# bind mount で上書きされるが、単体でも動くようにソースを入れておく
COPY . /app

# 起動時に vendor / .env / APP_KEY が無ければ用意する。
# /app は bind mount で上書きされるので、スクリプトは外に置く
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

# nginx から fastcgi で叩かれるポート。ホストには公開しない
EXPOSE 9000

CMD ["php-fpm"]
