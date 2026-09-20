# 静的配信のみ。ビルドツールを挟む段階になったら
# multi-stage build (node:xx-alpine -> nginx) に差し替える。
FROM nginx:1.27-alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf

# public/ がそのまま公開ディレクトリ。Dockerfile はイメージに含めない
COPY public/ /usr/share/nginx/html/

EXPOSE 80
