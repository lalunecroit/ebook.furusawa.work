-- テスト用データベース。
--
-- phpunit.mysql.xml (php artisan test -c phpunit.mysql.xml) の接続先。
-- RefreshDatabase がテストごとにテーブルを落として作り直すため、
-- 開発用の ebooks_local とは必ず分ける。
--
-- このファイルは compose の db-init サービスが docker compose up のたびに流す。
-- 既存のボリュームでも無ければ作られるよう、何度流しても同じ結果になる文だけを書く。
CREATE DATABASE IF NOT EXISTS ebooks_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- MYSQL_USER で作られる ebooks は MYSQL_DATABASE (ebooks_local) の権限しか
-- 持たないため、テスト用 DB の権限を明示的に与える。
GRANT ALL PRIVILEGES ON ebooks_test.* TO 'ebooks'@'%';
FLUSH PRIVILEGES;
