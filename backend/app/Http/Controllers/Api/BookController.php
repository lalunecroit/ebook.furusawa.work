<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookPageResource;
use App\Http\Resources\BookResource;
use App\Models\Book;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 書籍API。
 *
 * 書誌情報は books、ページ画像は book_pages から引く。
 * ソフトデリート済みの書籍は SoftDeletes のグローバルスコープで自動的に除外される。
 *
 * JSON の組み立ては App\Http\Resources 側が担当する。
 * ここは「取ってきて渡す」だけに保つ。
 */
class BookController extends Controller
{
    /**
     * GET /api/books/{book}
     * 書誌情報 + ページ一覧。
     *
     * $book はルートモデルバインディングで解決済み。
     * books.code で引かれ、見つからなければここに来る前に 404 になる。
     */
    public function show(Book $book): BookResource
    {
        $this->loadPages($book);

        return new BookResource($book);
    }

    /**
     * GET /api/books/{book}/pages
     * ページ一覧だけが欲しいとき用。
     */
    public function pages(Book $book): AnonymousResourceCollection
    {
        $this->loadPages($book);

        return BookPageResource::collection($book->pages);
    }

    /**
     * ページを読み込む。
     *
     * バインディングで解決された時点では本体しか引かれていないので、
     * ここでページ順に並べて1クエリで取る (1ページずつ引く N+1 を避ける)。
     */
    private function loadPages(Book $book): void
    {
        $book->load(['pages' => fn ($query) => $query->orderBy('page_no')]);
    }
}
