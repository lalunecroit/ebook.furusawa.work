<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookPage>
 */
class BookPageFactory extends Factory
{
    protected $model = BookPage::class;

    /**
     * ページ番号は 1 固定。複数ページを作るときは BookFactory::withPages() が
     * sequence() で 1,2,3... を振る (book_id と page_no の複合ユニークがあるため)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_no' => 1,
            'img_path' => $this->imgPath('sample', 1),
        ];
    }

    /**
     * 親の書籍に合わせて img_path を組み立てる。
     *
     * state のクロージャは (属性, 親モデル) を受け取れる。
     * BookFactory::withPages() から has() 経由で呼ばれたときは $book が入る。
     */
    public function forParentBook(): static
    {
        return $this->state(fn (array $attributes, ?Book $book) => [
            'img_path' => $this->imgPath($book?->code ?? 'sample', $attributes['page_no'] ?? 1),
        ]);
    }

    private function imgPath(string $code, int $pageNo): string
    {
        return sprintf('/books/%s/page-%02d.svg', $code, $pageNo);
    }
}
