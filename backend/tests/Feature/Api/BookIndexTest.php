<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_公開済みの書籍が新しい順に返る(): void
    {
        Book::factory()->withPages(2)->create(['code' => 'old', 'published_at' => '2026-01-01 00:00:00']);
        Book::factory()->withPages(2)->create(['code' => 'new', 'published_at' => '2026-06-01 00:00:00']);

        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'new')
            ->assertJsonPath('data.1.code', 'old')
            ->assertJsonStructure([
                'data' => [['code', 'title', 'description', 'published_at', 'total_pages', 'cover']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_未公開と公開予約は含まれない(): void
    {
        Book::factory()->withPages(2)->create(['code' => 'published']);
        Book::factory()->withPages(2)->unpublished()->create(['code' => 'draft']);
        Book::factory()->withPages(2)->create([
            'code' => 'scheduled',
            'published_at' => now()->addDay(),
        ]);

        $res = $this->getJson('/api/books')->assertOk();

        $this->assertSame(['published'], array_column($res->json('data'), 'code'));
    }

    public function test_論理削除済みは含まれない(): void
    {
        Book::factory()->withPages(2)->create(['code' => 'alive']);
        Book::factory()->withPages(2)->trashed()->create(['code' => 'deleted']);

        $res = $this->getJson('/api/books')->assertOk();

        $this->assertSame(['alive'], array_column($res->json('data'), 'code'));
    }

    public function test_一覧にはページ一覧を含めず表紙だけを返す(): void
    {
        Book::factory()->withPages(3)->create(['code' => 'sample']);

        $res = $this->getJson('/api/books')->assertOk();

        $res->assertJsonMissingPath('data.0.pages')
            ->assertJsonPath('data.0.total_pages', 3)
            ->assertJsonPath('data.0.cover.page_no', 1)
            ->assertJsonPath('data.0.cover.img_path', '/books/sample/page-01.svg');
    }

    public function test_per_pageでページ送りできる(): void
    {
        Book::factory()->count(5)->create();

        $this->getJson('/api/books?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $this->getJson('/api/books?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_per_pageが範囲外なら422(): void
    {
        $this->getJson('/api/books?per_page=0')->assertStatus(422);
        $this->getJson('/api/books?per_page=101')->assertStatus(422);
        $this->getJson('/api/books?per_page=abc')->assertStatus(422);
    }

    public function test_1冊もなければ空配列を返す(): void
    {
        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }
}
