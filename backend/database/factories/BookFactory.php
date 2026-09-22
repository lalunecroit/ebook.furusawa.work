<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    protected $model = Book::class;

    /**
     * code は生存行の中で一意である必要があるため unique() を通す。
     * pages_count は実際のページ数と揃えたいので、ここでは 0 にしておき
     * withPages() が作成後に実数へ更新する。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'pages_count' => 0,
            'published_at' => now(),
        ];
    }

    /**
     * ページ付きで作る。
     *
     * page_no は 1 から連番、img_path は親の code から組み立てる。
     * 作成後に pages_count を実数へ合わせる (一覧用の非正規化カラム)。
     */
    public function withPages(int $count = 2): static
    {
        return $this
            ->has(
                BookPage::factory()
                    ->count($count)
                    ->sequence(fn (Sequence $sequence) => ['page_no' => $sequence->index + 1])
                    ->forParentBook(),
                'pages'
            )
            ->afterCreating(function (Book $book) {
                $book->update(['pages_count' => $book->pages()->count()]);
            });
    }

    /**
     * 論理削除済みの状態。is_active は生成列なので DB 側が NULL にする。
     */
    public function trashed(): static
    {
        return $this->state(['deleted_at' => now()]);
    }

    /**
     * 未公開 (published_at が null)。
     */
    public function unpublished(): static
    {
        return $this->state(['published_at' => null]);
    }
}
