<?php

namespace Database\Seeders;

use App\Models\Book;
use Illuminate\Database\Seeder;

/**
 * ビューア動作確認用のサンプル書籍。
 *
 * 書誌情報と章立ては database/seeders/data/sample-books.json に置いてある。
 * ページ画像を作る tools/bin/generate-sample-pages.php も同じファイルを読むので、
 * DB の内容と cdn/public/books/<code>/ の画像がずれない。
 */
class BookSeeder extends Seeder
{
    /** 1冊あたりのページ数。画像の生成側と揃えている */
    private const PAGES_PER_BOOK = 10;

    /**
     * 何度流しても同じ状態になるよう updateOrCreate で書く。
     */
    public function run(): void
    {
        foreach ($this->catalog() as $data) {
            $book = Book::updateOrCreate(
                ['code' => $data['code']],
                [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'pages_count' => self::PAGES_PER_BOOK,
                    'published_at' => $data['published_at'],
                ],
            );

            foreach (range(1, self::PAGES_PER_BOOK) as $pageNo) {
                $book->pages()->updateOrCreate(
                    ['page_no' => $pageNo],
                    ['img_path' => sprintf('/books/%s/page-%02d.svg', $data['code'], $pageNo)],
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $path = database_path('seeders/data/sample-books.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
