<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookRequest;
use App\Models\Book;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 管理画面の書籍編集。
 *
 * 公開側の API (Api\BookController) と違い、未公開・公開予約の書籍も扱う。
 * ページ画像の登録はまだ扱わず、書誌情報だけを対象にしている。
 */
class BookController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * GET /admin/books
     * 未公開も含めた全件。公開側の published() スコープは使わない。
     */
    public function index(): View
    {
        $books = Book::withCount('pages')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('admin.books.index', ['books' => $books]);
    }

    /**
     * GET /admin/books/create
     */
    public function create(): View
    {
        // 空のモデルを渡してフォームを使い回す (新規と編集で同じ partial)
        return view('admin.books.create', ['book' => new Book]);
    }

    /**
     * POST /admin/books
     */
    public function store(BookRequest $request): RedirectResponse
    {
        $book = Book::create($request->validated());

        return redirect()
            ->route('admin.books.edit', $book)
            ->with('status', "「{$book->title}」を登録しました。");
    }

    /**
     * GET /admin/books/{book}/edit
     */
    public function edit(Book $book): View
    {
        return view('admin.books.edit', ['book' => $book]);
    }

    /**
     * PUT /admin/books/{book}
     */
    public function update(BookRequest $request, Book $book): RedirectResponse
    {
        $book->update($request->validated());

        return redirect()
            ->route('admin.books.edit', $book)
            ->with('status', "「{$book->title}」を更新しました。");
    }
}
