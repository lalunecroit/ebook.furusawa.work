# 開発用のツールコンテナ。
#
# API (backend) とは役割を分けている。リクエストを受けるのではなく、
# CLI から単発で回す処理を担当する。常駐しないので compose では profiles で
# 通常の up から外し、run で都度起動する。
#
# 今のところ中身はサンプル画像の生成だが、画像のリサイズ・形式変換など
# 画像まわりの処理を見越して GD を入れてある。
FROM php:8.4-cli-alpine

# GD (JPEG / PNG / WebP / TrueType)。リサイズやサムネイル生成で使う。
# ビルドに必要なヘッダは仮想パッケージにまとめ、入れ終わったら消す。
RUN apk add --no-cache libjpeg-turbo libpng libwebp freetype \
    && apk add --no-cache --virtual .build-deps \
        libjpeg-turbo-dev libpng-dev libwebp-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd \
    && apk del .build-deps

# リポジトリ全体を /work にマウントして使う (compose 側で指定)
WORKDIR /work

# 引数なしで走らせたときはサンプル画像の生成
CMD ["php", "tools/bin/generate-sample-pages.php"]
