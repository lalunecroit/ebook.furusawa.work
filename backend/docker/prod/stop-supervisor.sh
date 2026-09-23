#!/bin/sh
# supervisord の eventlistener。
# 監視対象のプロセスが終了したら、親 (supervisord) に SIGQUIT を送って
# コンテナごと終了させる。片肺運転のまま 502 を返し続けるのを防ぐため。
#
# eventlistener のプロトコル上、まず "READY" を出してイベントを待つ。
printf "READY\n"

while read -r line; do
  echo "[stop-supervisor] プロセスが終了しました: ${line}" >&2
  kill -SIGQUIT "${PPID}"
done
