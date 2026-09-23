<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookPageUploadRequest;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * 管理画面のページ画像。
 *
 * 画像の実体は cdn ディスク (ローカルでは cdn/public/) に置き、
 * DB には CDN ルートからの相対パス (books/<code>/page-01.svg) だけを持つ。
 * 表示用の URL は API 側 (BookPageResource) が組み立てる。
 */
class BookPageController extends Controller
{
    public function index(Book $book): View
    {
        $book->load(['pages' => fn ($query) => $query->orderBy('page_no')]);

        return view('admin.books.pages', ['book' => $book]);
    }

    /**
     * POST /admin/books/{book}/pages
     *
     * 1枚ずつ受け取る。page_no を省略すると末尾に追加、
     * 既存のページ番号を指定すると差し替えになる。
     * ドラッグ&ドロップから fetch で呼ぶので JSON を返す。
     */
    public function store(BookPageUploadRequest $request, Book $book): JsonResponse
    {
        $file = $request->file('image');
        $pageNo = (int) ($request->input('page_no') ?: $book->pages()->max('page_no') + 1);

        $existing = $book->pages()->firstWhere('page_no', $pageNo);

        // 差し替えで拡張子が変わることがあるので、古いファイルは先に消す
        if ($existing !== null) {
            Storage::disk('cdn')->delete($this->relative($existing->img_path));
        }

        // DB には CDN ルートからの絶対パス (先頭スラッシュ付き) を入れる。
        // ディスク操作はルート相対なので、渡すときにスラッシュを落とす。
        $path = sprintf('/books/%s/page-%02d.%s', $book->code, $pageNo, $file->extension());
        $relative = $this->relative($path);
        Storage::disk('cdn')->putFileAs(dirname($relative), $file, basename($relative));

        $page = $book->pages()->updateOrCreate(
            ['page_no' => $pageNo],
            ['img_path' => $path],
        );

        // 同じパスに上書きしたときは属性が変わらず updated_at が動かない。
        // updated_at は画像URLのキャッシュバスター (?v=) の元ネタなので、
        // 差し替えたことを URL に反映させるために明示的に更新する。
        $page->touch();

        // 一覧表示用の非正規化カラムを実数に合わせる
        $book->update(['pages_count' => $book->pages()->count()]);

        return response()->json([
            'page' => $this->present($page->refresh()),
            'pages_count' => $book->pages_count,
        ], $existing === null ? 201 : 200);
    }

    /**
     * DELETE /admin/books/{book}/pages/{page}
     * 画像ごと削除する。ページ番号の振り直しはしない。
     */
    public function destroy(Book $book, BookPage $page): JsonResponse
    {
        abort_if($page->book_id !== $book->id, 404);

        Storage::disk('cdn')->delete($this->relative($page->img_path));
        $page->delete();

        $book->update(['pages_count' => $book->pages()->count()]);

        return response()->json(['pages_count' => $book->pages_count]);
    }

    /** ディスク操作用にルート相対へ直す (DB の値は先頭スラッシュ付き) */
    private function relative(string $imgPath): string
    {
        return ltrim($imgPath, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BookPage $page): array
    {
        return [
            'id' => $page->id,
            'page_no' => $page->page_no,
            'img_path' => $page->img_path,
            'url' => $page->url,
        ];
    }
}
