# ローカル開発用の API コンテナ。
# 本番に寄せる段階では php-fpm + nginx (または RoadRunner/Octane) に差し替える。
# ここでは artisan serve をそのまま使い、ソースは compose 側で bind mount する。
FROM php:8.4-cli-alpine

# pdo_mysql は DB を入れる段階で必要になるので先に入れておく
RUN docker-php-ext-install pdo_mysql

WORKDIR /app

# bind mount で上書きされるが、単体でも動くようにソースを入れておく
COPY . /app

EXPOSE 8000

# 0.0.0.0 で待たないとコンテナ外から届かない
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
