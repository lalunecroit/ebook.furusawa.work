<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * 書籍API。
 *
 * 書誌情報は books、ページ画像は book_pages から引く。
 * ソフトデリート済みの書籍は SoftDeletes のグローバルスコープで自動的に除外される。
 */
class BookController extends Controller
{
    /**
     * GET /api/books/{code}
     * 書誌情報 + ページ一覧。ビューアはこれ1本で起動できる。
     */
    public function show(string $code): JsonResponse
    {
        $book = $this->findOrFail($code);

        return response()->json([
            'data' => [
                'code' => $book->code,
                'title' => $book->title,
                'description' => $book->description,
                'published_at' => $book->published_at?->toIso8601String(),
                // books.pages_count ではなく実際の行数を返す。pages_count は一覧表示用の
                // 非正規化カラムで、ページを差し替えた直後などにズレうるため。
                'total_pages' => $book->pages->count(),
                'pages' => $this->pageList($book->pages),
            ],
        ]);
    }

    /**
     * GET /api/books/{code}/pages
     * ページ一覧だけが欲しいとき用。
     */
    public function pages(string $code): JsonResponse
    {
        $book = $this->findOrFail($code);

        return response()->json([
            'data' => $this->pageList($book->pages),
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
     *
     * @param  Collection<int, BookPage>  $pages
     * @return list<array{page_no: int, img_path: string, updated_at: string, url: string}>
     */
    private function pageList(Collection $pages): array
    {
        $base = config('cdn.base_url');

        return $pages->map(static fn (BookPage $page): array => [
            'page_no' => $page->page_no,
            'img_path' => $page->img_path,
            'updated_at' => $page->updated_at->toIso8601String(),
            'url' => $base.$page->img_path.'?v='.$page->updated_at->getTimestamp(),
        ])->values()->all();
    }

    /**
     * ページは必ず使うので eager load しておく (N+1 回避)。
     */
    private function findOrFail(string $code): Book
    {
        $book = Book::with(['pages' => fn ($query) => $query->orderBy('page_no')])
            ->where('code', $code)
            ->first();

        abort_if($book === null, 404, "Book [{$code}] not found.");

        return $book;
    }
}
