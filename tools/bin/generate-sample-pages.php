<?php

declare(strict_types=1);

/**
 * サンプル書籍のページ画像 (SVG) を生成する。
 *
 * 書誌情報と章立ては backend/database/seeders/data/sample-books.json に置いてある。
 * 同じファイルを BookSeeder も読むので、画像と DB の内容がずれない。
 *
 * 出力先は <出力ルート>/<code>/page-01.svg … page-10.svg。
 * ファイル名の規則は book_pages.img_path に入れている値と対応している。
 *
 * 使い方 (tools コンテナ):
 *     docker compose run --rm tools                                  # 全冊を生成
 *     docker compose run --rm tools php tools/bin/generate-sample-pages.php --only=sample
 *     docker compose run --rm tools php tools/bin/generate-sample-pages.php /work/tmp
 *
 * ホストに PHP があれば直接でも動く:
 *     php tools/bin/generate-sample-pages.php
 */
const W = 800;
const H = 1131;   // A5 相当の縦横比 (1:1.414)

const FONT = "'Hiragino Sans','Noto Sans JP',sans-serif";

/** 既定のアクセントカラー。accent_offset の分だけ回して各冊に割り当てる */
const DEFAULT_ACCENTS = [
    '#1f3a5f', '#2f6feb', '#0f8a7a', '#b5622a', '#7a3f9d',
    '#c0392b', '#16794c', '#8a6d1f', '#3b5bdb', '#1f3a5f',
];

function esc(string $s): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

function text(
    int $x,
    int $y,
    string $s,
    int $size = 24,
    string $color = '#1b1f24',
    string $weight = '400',
    string $anchor = 'start',
): string {
    return sprintf(
        '<text x="%d" y="%d" font-family="%s" font-size="%d" font-weight="%s" fill="%s" text-anchor="%s">%s</text>',
        $x, $y, FONT, $size, $weight, $color, $anchor, esc($s),
    );
}

/**
 * 本文っぽいグレーの帯を描いて「文章が組まれている」感を出す。
 *
 * @return array{0: string, 1: int} 生成した SVG と、次に描き始める y 座標
 */
function bodyLines(int $top, int $count, int $indent = 0): array
{
    $widths = [640, 600, 655, 580, 630, 610, 660, 545];

    $out = '';
    $y = $top;
    for ($i = 0; $i < $count; $i++) {
        // 行長をばらつかせて自然に見せる
        $width = $widths[$i % 8] - $indent;
        $out .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="10" rx="5" fill="#1b1f24" opacity="0.14"/>',
            80 + $indent, $y, $width,
        );
        $y += 30;
    }

    return [$out, $y];
}

/** 全ページ共通の枠・ノンブル (ページ番号) */
function frame(int $pageNo, string $accent, string $inner): string
{
    $rule = sprintf(
        '<line x1="80" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-opacity="0.3" stroke-width="1"/>',
        H - 90, W - 80, H - 90, $accent,
    );
    $nombre = text(intdiv(W, 2), H - 55, "- {$pageNo} -", size: 20, color: '#8b95a1', anchor: 'middle');

    // ヒアドキュメントは定数 (W / H) を展開しないので sprintf で組み立てる
    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">'."\n"
        .'  <rect width="%d" height="%d" fill="#ffffff"/>'."\n"
        .'  <rect x="0" y="0" width="%d" height="12" fill="%s"/>'."\n"
        .'  %s'."\n"
        .'  %s'."\n"
        .'  %s'."\n"
        .'</svg>'."\n",
        W, H, W, H,
        W, H,
        W, $accent,
        $inner,
        $rule,
        $nombre,
    );
}

/**
 * @param  array<string, mixed>  $book
 */
function cover(array $book, string $accent): string
{
    $c = $book['cover'];

    $inner = "\n"
        .sprintf('  <rect width="%d" height="%d" fill="%s"/>', W, H, $accent)."\n"
        .sprintf(
            '  <rect x="70" y="70" width="%d" height="%d" fill="none" stroke="#ffffff" stroke-opacity="0.45" stroke-width="2"/>',
            W - 140, H - 140,
        )."\n"
        .'  '.text(intdiv(W, 2), 430, $c['line1'], size: 40, color: '#ffffff', weight: '300', anchor: 'middle')."\n"
        .'  '.text(intdiv(W, 2), 510, $c['line2'], size: 56, color: '#ffffff', weight: '700', anchor: 'middle')."\n"
        .sprintf(
            '  <line x1="260" y1="560" x2="%d" y2="560" stroke="#ffffff" stroke-opacity="0.6" stroke-width="2"/>',
            W - 260,
        )."\n"
        .'  '.text(intdiv(W, 2), 620, $c['subtitle'], size: 24, color: '#ffffff', anchor: 'middle')."\n"
        .'  '.text(intdiv(W, 2), H - 140, $c['footer'], size: 22, color: '#ffffff', anchor: 'middle')."\n";

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">%s</svg>'."\n",
        W, H, W, H, $inner,
    );
}

/**
 * @param  array<string, mixed>  $book
 */
function toc(array $book, int $pageNo, string $accent): string
{
    $out = text(80, 160, '目 次', size: 42, color: $accent, weight: '700');
    $out .= sprintf('<line x1="80" y1="190" x2="%d" y2="190" stroke="%s" stroke-width="3"/>', W - 80, $accent);

    $y = 270;
    foreach ($book['chapters'] as $i => $chapter) {
        // 第1章は3ページ目から始まる (1:表紙 / 2:目次)
        $pageOfChapter = sprintf('%02d', $i + 3);

        $out .= text(80, $y, sprintf('第%d章', $i + 1), size: 20, color: '#8b95a1');
        $out .= text(180, $y, $chapter['title'], size: 26);
        $out .= text(W - 80, $y, $pageOfChapter, size: 22, color: '#8b95a1', anchor: 'end');
        $out .= sprintf(
            '<line x1="180" y1="%d" x2="%d" y2="%d" stroke="#1b1f24" stroke-opacity="0.12" stroke-dasharray="2 4"/>',
            $y + 12, W - 110, $y + 12,
        );
        $y += 80;
    }

    return frame($pageNo, $accent, $out);
}

/**
 * @param  array<string, mixed>  $book
 */
function chapter(array $book, int $pageNo, string $accent, int $num, string $title): string
{
    $out = text(80, 150, "CHAPTER {$num}", size: 18, color: $accent, weight: '700');
    $out .= text(80, 210, $title, size: 34, weight: '700');
    $out .= sprintf('<line x1="80" y1="240" x2="240" y2="240" stroke="%s" stroke-width="4"/>', $accent);

    [$lines, $y] = bodyLines(300, 9);
    $out .= $lines;

    // 引用ブロック
    $out .= sprintf('<rect x="80" y="%d" width="4" height="110" fill="%s"/>', $y + 30, $accent);
    $out .= text(110, $y + 70, $book['quote'][0], size: 22, color: '#5b6672');
    $out .= text(110, $y + 105, $book['quote'][1], size: 22, color: '#5b6672');

    [$lines2] = bodyLines($y + 190, 8);
    $out .= $lines2;

    return frame($pageNo, $accent, $out);
}

/**
 * @param  array<string, mixed>  $book
 */
function figure(array $book, int $pageNo, string $accent, int $num, string $title): string
{
    $out = text(80, 150, "CHAPTER {$num}", size: 18, color: $accent, weight: '700');
    $out .= text(80, 210, $title, size: 34, weight: '700');
    $out .= sprintf('<line x1="80" y1="240" x2="240" y2="240" stroke="%s" stroke-width="4"/>', $accent);

    [$lines, $y] = bodyLines(300, 5);
    $out .= $lines;

    // 図版: state -> render -> DOM
    $fy = $y + 40;
    foreach ([['state', 80], ['render()', 320], ['DOM', 560]] as [$label, $bx]) {
        $out .= sprintf(
            '<rect x="%d" y="%d" width="160" height="80" rx="8" fill="none" stroke="%s" stroke-width="2"/>',
            $bx, $fy, $accent,
        );
        $out .= text($bx + 80, $fy + 48, $label, size: 22, color: $accent, anchor: 'middle');
    }
    foreach ([240, 480] as $bx) {
        $out .= sprintf(
            '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="2"/>',
            $bx, $fy + 40, $bx + 80, $fy + 40, $accent,
        );
        $out .= sprintf('<path d="M%d %d l-10 -5 v10 z" fill="%s"/>', $bx + 80, $fy + 40, $accent);
    }
    $out .= text(
        intdiv(W, 2),
        $fy + 130,
        sprintf('図 %d-1  %s', $num, $book['figure_caption']),
        size: 18,
        color: '#8b95a1',
        anchor: 'middle',
    );

    [$lines2] = bodyLines($fy + 175, 7);
    $out .= $lines2;

    return frame($pageNo, $accent, $out);
}

function afterword(int $pageNo, string $accent): string
{
    $out = text(80, 170, 'あとがき', size: 34, weight: '700');
    $out .= sprintf('<line x1="80" y1="200" x2="240" y2="200" stroke="%s" stroke-width="4"/>', $accent);

    [$lines] = bodyLines(270, 14);
    $out .= $lines;

    return frame($pageNo, $accent, $out);
}

/**
 * @param  array<string, mixed>  $book
 */
function colophon(array $book, int $pageNo, string $accent): string
{
    $rows = [
        ['書名', $book['title']],
        ['発行', $book['colophon']['issued']],
        ['著者', $book['colophon']['author']],
        ['発行所', $book['colophon']['publisher']],
    ];

    $out = text(intdiv(W, 2), 300, '奥 付', size: 30, color: $accent, weight: '700', anchor: 'middle');
    $out .= sprintf('<line x1="280" y1="330" x2="%d" y2="330" stroke="%s" stroke-width="2"/>', W - 280, $accent);

    $y = 410;
    foreach ($rows as [$k, $v]) {
        $out .= text(200, $y, $k, size: 20, color: '#8b95a1');
        $out .= text(320, $y, $v, size: 20);
        $y += 50;
    }
    $out .= text(intdiv(W, 2), H - 200, '© 2026 furusawa.work', size: 18, color: '#8b95a1', anchor: 'middle');

    return frame($pageNo, $accent, $out);
}

/**
 * 1冊分 (10ページ) を組み立てる。
 *
 * @param  array<string, mixed>  $book
 * @return list<string> ページ番号順の SVG
 */
function buildBook(array $book): array
{
    // accents が無い本は既定パレットを accent_offset だけ回して使う
    $accents = $book['accents'] ?? array_map(
        static fn (int $i): string => DEFAULT_ACCENTS[($i + (int) ($book['accent_offset'] ?? 0)) % count(DEFAULT_ACCENTS)],
        range(0, count(DEFAULT_ACCENTS) - 1),
    );

    $pages = [
        static fn (int $n, string $a): string => cover($book, $a),
        static fn (int $n, string $a): string => toc($book, $n, $a),
    ];

    // 3〜8ページ目が第1〜6章。type で本文ページか図版ページかを選ぶ
    foreach ($book['chapters'] as $i => $chapter) {
        $num = $i + 1;
        $title = $chapter['title'];
        $pages[] = ($chapter['type'] ?? 'chapter') === 'figure'
            ? static fn (int $n, string $a): string => figure($book, $n, $a, $num, $title)
            : static fn (int $n, string $a): string => chapter($book, $n, $a, $num, $title);
    }

    $pages[] = static fn (int $n, string $a): string => afterword($n, $a);
    $pages[] = static fn (int $n, string $a): string => colophon($book, $n, $a);

    $svgs = [];
    foreach ($pages as $i => $build) {
        $svgs[] = $build($i + 1, $accents[$i % count($accents)]);
    }

    return $svgs;
}

// ---------------------------------------------------------------- 実行

$rootDir = dirname(__DIR__, 2);   // tools/bin/ から見て2つ上がリポジトリルート
$catalogPath = $rootDir.'/backend/database/seeders/data/sample-books.json';

$outRoot = null;
$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, strlen('--only='));

        continue;
    }
    $outRoot = $arg;
}
$outRoot ??= $rootDir.'/cdn/public/books';

$catalog = json_decode((string) file_get_contents($catalogPath), true, flags: JSON_THROW_ON_ERROR);

$written = 0;
foreach ($catalog as $book) {
    if ($only !== null && $book['code'] !== $only) {
        continue;
    }

    $dir = $outRoot.'/'.$book['code'];
    if (! is_dir($dir) && ! mkdir($dir, 0o755, recursive: true) && ! is_dir($dir)) {
        fwrite(STDERR, "出力先を作成できませんでした: {$dir}\n");
        exit(1);
    }

    foreach (buildBook($book) as $i => $svg) {
        $path = sprintf('%s/page-%02d.svg', $dir, $i + 1);
        if (file_put_contents($path, $svg) === false) {
            fwrite(STDERR, "書き込みに失敗しました: {$path}\n");
            exit(1);
        }
        $written++;
    }

    // リポジトリ内なら相対パスで、外なら絶対パスのまま表示する
    $shown = str_starts_with($dir, $rootDir.'/') ? substr($dir, strlen($rootDir) + 1) : $dir;
    printf("wrote %-40s %s\n", $shown.'/', $book['title']);
}

if ($written === 0) {
    fwrite(STDERR, "対象の書籍がありません".($only !== null ? " (--only={$only})" : '')."\n");
    exit(1);
}

printf("\n%d books / %d pages generated.\n", $written / 10, $written);
