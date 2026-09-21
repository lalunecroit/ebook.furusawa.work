<?php

namespace Database\Seeders;

use App\Models\Book;
use Illuminate\Database\Seeder;

/**
 * ビューア動作確認用のサンプル書籍。
 *
 * 画像の実体は cdn/public/books/sample/ に置いてある 10 枚の SVG。
 * BookController にベタ書きしていた内容をそのまま DB に移したもの。
 */
class BookSeeder extends Seeder
{
    /**
     * 何度流しても同じ状態になるよう updateOrCreate で書く。
     */
    public function run(): void
    {
        $book = Book::updateOrCreate(
            ['code' => 'sample'],
            [
                'title' => 'Docker で作る電子書籍サービス',
                'description' => 'フロントエンド編。ページめくりビューアの動作確認用サンプル。',
                'pages_count' => 10,
                'published_at' => '2026-09-20 00:00:00',
            ],
        );

        foreach (range(1, 10) as $pageNo) {
            $book->pages()->updateOrCreate(
                ['page_no' => $pageNo],
                ['img_path' => sprintf('/books/sample/page-%02d.svg', $pageNo)],
            );
        }
    }
}
