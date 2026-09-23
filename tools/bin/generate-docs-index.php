<?php

declare(strict_types=1);

/**
 * docs ビューア用のファイル一覧 (index.json) を生成する。
 *
 * ローカルでは nginx の autoindex からサイドバーを組み立てているが、
 * GCS には autoindex が無い。本番に出す前にこのスクリプトで一覧を
 * 静的な JSON に固め、ビューア側はそれを読む。
 *
 * 出力先の docs/public/index.json は .gitignore に入れてある。
 * ローカルに置かないことで autoindex 側が使われ、md を足した瞬間に
 * サイドバーへ出る挙動が保たれる (一覧が古いまま出ることがない)。
 *
 * 使い方 (tools コンテナ):
 *     docker compose run --rm tools php tools/bin/generate-docs-index.php
 *     docker compose run --rm tools php tools/bin/generate-docs-index.php --check
 *
 * ホストに PHP があれば直接でも動く:
 *     php tools/bin/generate-docs-index.php
 */

/** 一覧を作るディレクトリ。docs/public/index.html の DIRS と対応させる */
const DIRS = ['md', 'artifact'];

$rootDir = dirname(__DIR__, 2);   // tools/bin/ から見て2つ上がリポジトリルート
$docsDir = $rootDir.'/docs/public';
$outPath = $docsDir.'/index.json';

// --check は生成せず、既存の index.json が最新かどうかだけ見る (CI 用)
$check = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $check = true;
        continue;
    }

    fwrite(STDERR, "不明な引数: {$arg}\n");
    exit(1);
}

$index = [];
foreach (DIRS as $dir) {
    $path = $docsDir.'/'.$dir;
    if (! is_dir($path)) {
        fwrite(STDERR, "ディレクトリがありません: {$path}\n");
        exit(1);
    }

    // ディレクトリとドットファイルは除く。並びはビューア側と揃えて昇順
    $files = array_values(array_filter(
        scandir($path) ?: [],
        static fn (string $name): bool => $name[0] !== '.' && is_file($path.'/'.$name),
    ));
    sort($files);

    // キーは末尾スラッシュ付き。ビューアの DIRS がこの形で持っている
    $index[$dir.'/'] = $files;
}

$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

if ($check) {
    $current = is_file($outPath) ? (string) file_get_contents($outPath) : null;
    if ($current !== $json) {
        fwrite(STDERR, "index.json が最新ではありません。生成し直してください。\n");
        exit(1);
    }

    printf("index.json is up to date.\n");
    exit(0);
}

if (file_put_contents($outPath, $json) === false) {
    fwrite(STDERR, "書き込みに失敗しました: {$outPath}\n");
    exit(1);
}

foreach ($index as $dir => $files) {
    printf("%-12s %d files\n", $dir, count($files));
}

printf("\nwrote %s\n", substr($outPath, strlen($rootDir) + 1));
