<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(string $code = 'sample'): Book
    {
        $book = Book::create([
            'code' => $code,
            'title' => 'テスト書籍',
            'description' => '説明',
            'pages_count' => 2,
            'published_at' => '2026-09-20 00:00:00',
        ]);

        $book->pages()->createMany([
            ['page_no' => 1, 'img_path' => '/books/sample/page-01.svg'],
            ['page_no' => 2, 'img_path' => '/books/sample/page-02.svg'],
        ]);

        return $book;
    }

    public function test_書誌情報とページ一覧が返る(): void
    {
        $this->makeBook();

        $res = $this->getJson('/api/books/sample');

        $res->assertOk()
            ->assertJsonPath('data.code', 'sample')
            ->assertJsonPath('data.title', 'テスト書籍')
            ->assertJsonPath('data.total_pages', 2)
            ->assertJsonPath('data.pages.0.page_no', 1)
            ->assertJsonPath('data.pages.0.img_path', '/books/sample/page-01.svg')
            ->assertJsonStructure([
                'data' => [
                    'code', 'title', 'description', 'published_at', 'total_pages',
                    'pages' => [['page_no', 'img_path', 'updated_at', 'url']],
                ],
            ]);
    }

    public function test_画像URLにCDNのベースURLとキャッシュバスターが付く(): void
    {
        config(['cdn.base_url' => 'http://cdn.test']);
        $book = $this->makeBook();
        $version = $book->pages()->where('page_no', 1)->first()->updated_at->getTimestamp();

        $this->getJson('/api/books/sample')
            ->assertJsonPath('data.pages.0.url', "http://cdn.test/books/sample/page-01.svg?v={$version}");
    }

    public function test_ページ一覧だけを返すエンドポイント(): void
    {
        $this->makeBook();

        $this->getJson('/api/books/sample/pages')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_存在しないcodeは404(): void
    {
        $this->getJson('/api/books/notfound')->assertNotFound();
    }

    public function test_ソフトデリート済みの書籍は404になり復元すると戻る(): void
    {
        $book = $this->makeBook();

        $book->delete();
        $this->getJson('/api/books/sample')->assertNotFound();

        $book->restore();
        $this->getJson('/api/books/sample')->assertOk();
    }

    public function test_生存行のcode重複は弾かれ削除済みとは重複できる(): void
    {
        $book = $this->makeBook();

        // 削除済みなら同じ code を何件でも持てる
        $book->delete();
        $this->makeBook()->delete();
        $again = $this->makeBook();
        $this->assertSame('sample', $again->code);

        // 生存行が既にあるなら弾かれる
        $this->expectException(UniqueConstraintViolationException::class);
        Book::create(['code' => 'sample', 'title' => '重複', 'pages_count' => 0]);
    }
}
