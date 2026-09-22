<?php

// phpunit.mysql.xml 用の bootstrap。
// テスト用 DB (ebooks_test) が無ければ作ってから、通常どおり autoload を読む。
// ebooks ユーザーは ebooks_local の権限しか持たないため、作成と権限付与は root で行う。
// どちらも何度流しても同じ結果になる文なので、毎回実行してよい。

$database = $_SERVER['DB_DATABASE'];
$username = $_SERVER['DB_USERNAME'];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', $_SERVER['DB_HOST'], $_SERVER['DB_PORT']),
    $_SERVER['DB_ROOT_USERNAME'],
    $_SERVER['DB_ROOT_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$username}'@'%'");

require __DIR__.'/../vendor/autoload.php';
