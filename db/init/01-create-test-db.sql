-- テスト用データベース。
--
-- phpunit.mysql.xml (php artisan test -c phpunit.mysql.xml) の接続先。
-- RefreshDatabase がテストごとにテーブルを落として作り直すため、
-- 開発用の ebooks_local とは必ず分ける。
--
-- このファイルは /docker-entrypoint-initdb.d/ に置かれ、
-- MySQL のデータディレクトリが空のとき (= 初回起動時) にだけ実行される。
-- 既にボリュームがある状態で追加しても動かないので、その場合は
-- docker compose down -v でボリュームごと作り直すこと。
CREATE DATABASE IF NOT EXISTS ebooks_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- MYSQL_USER で作られる ebooks は MYSQL_DATABASE (ebooks_local) の権限しか
-- 持たないため、テスト用 DB の権限を明示的に与える。
GRANT ALL PRIVILEGES ON ebooks_test.* TO 'ebooks'@'%';
FLUSH PRIVILEGES;
