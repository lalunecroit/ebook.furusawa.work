<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * 書籍API。
 *
 * 現時点では books / books_pages テーブルを用意していないため、
 * 書誌情報とページ画像のパスをこのクラスにベタ書きしている。
 * DB を入れる段階で self::BOOKS を Eloquent のクエリに置き換える。
 */
class BookController extends Controller
{
    /**
     * 暫定の書籍データ。
     * キーが slug (将来の books.slug 相当)、pages が books_pages 相当。
     */
    private const BOOKS = [
        'sample' => [
            'title' => 'Docker で作る電子書籍サービス',
            'description' => 'フロントエンド編。ページめくりビューアの動作確認用サンプル。',
            'published_at' => '2026-09-20',
            // books_pages 相当。img_path は CDN のドキュメントルートからの相対パス、
            // updated_at はキャッシュバスターの元ネタ (books_pages.updated_at)。
            // 管理画面から画像を差し替えたらこの値を更新する = URL が変わる。
            'pages' => [
                ['page_no' => 1,  'img_path' => '/books/sample/page-01.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 2,  'img_path' => '/books/sample/page-02.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 3,  'img_path' => '/books/sample/page-03.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 4,  'img_path' => '/books/sample/page-04.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 5,  'img_path' => '/books/sample/page-05.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 6,  'img_path' => '/books/sample/page-06.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 7,  'img_path' => '/books/sample/page-07.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 8,  'img_path' => '/books/sample/page-08.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 9,  'img_path' => '/books/sample/page-09.svg', 'updated_at' => '2026-09-20 20:00:00'],
                ['page_no' => 10, 'img_path' => '/books/sample/page-10.svg', 'updated_at' => '2026-09-20 20:00:00'],
            ],
        ],
    ];

    /**
     * GET /api/books/{slug}
     * 書誌情報 + ページ一覧。ビューアはこれ1本で起動できる。
     */
    public function show(string $slug): JsonResponse
    {
        $book = $this->findOrFail($slug);

        return response()->json([
            'data' => [
                'slug' => $slug,
                'title' => $book['title'],
                'description' => $book['description'],
                'published_at' => $book['published_at'],
                'total_pages' => count($book['pages']),
                'pages' => $this->pageList($book['pages']),
            ],
        ]);
    }

    /**
     * GET /api/books/{slug}/pages
     * ページ一覧だけが欲しいとき用。
     */
    public function pages(string $slug): JsonResponse
    {
        $book = $this->findOrFail($slug);

        return response()->json([
            'data' => $this->pageList($book['pages']),
        ]);
    }

    /**
     * img_path に CDN のベースURLとキャッシュバスターを足して、
     * ブラウザがそのまま使えるURLにする。
     *
     * CDN 側は 1年 + immutable でキャッシュさせているため、同じ URL のままでは
     * 画像を差し替えてもブラウザが取りに来ない。そこで updated_at を ?v= に載せ、
     * 更新された画像だけ URL が変わるようにしている。
     * (API 自体は Cache-Control: no-cache なので、新しい ?v= は次のリロードで届く)
     */
    private function pageList(array $pages): array
    {
        $base = config('cdn.base_url');

        return array_map(static function (array $page) use ($base): array {
            $version = Carbon::parse($page['updated_at'])->getTimestamp();

            return [
                'page_no' => $page['page_no'],
                'img_path' => $page['img_path'],
                'updated_at' => Carbon::parse($page['updated_at'])->toIso8601String(),
                'url' => $base.$page['img_path'].'?v='.$version,
            ];
        }, $pages);
    }

    /**
     * @return array{title: string, description: string, published_at: string, pages: list<array{page_no: int, img_path: string, updated_at: string}>}
     */
    private function findOrFail(string $slug): array
    {
        return self::BOOKS[$slug] ?? abort(404, "Book [{$slug}] not found.");
    }
}
