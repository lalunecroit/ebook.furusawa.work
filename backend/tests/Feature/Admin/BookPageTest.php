<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 画像は UploadedFile::fake()->image() で作る (GD が実際の画像を生成するので、
 * MIME 判定まで本物のバイト列で検証できる)。
 * サイズ上限のテストだけは中身が要らないので create() を使う。
 */
class BookPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 実際の cdn/public/ を汚さないよう、ディスクを差し替える
        Storage::fake('cdn');

        // 管理画面はログイン必須。認証そのものの検証は AuthTest で行う
        $this->actingAs(User::factory()->create());
    }

    /* ---------------------------------------------------------------- 画面 */

    public function test_ページ画像の管理画面が表示される(): void
    {
        $book = Book::factory()->withPages(2)->create(['code' => 'sample']);

        $this->get(route('admin.books.pages.index', $book))
            ->assertOk()
            ->assertSee('ページ画像')
            ->assertSee('/books/sample/page-01.svg');
    }

    public function test_存在しない書籍のページ画面は404(): void
    {
        $this->get(route('admin.books.pages.index', 'no-such-book'))->assertNotFound();
    }

    /* ------------------------------------------------------------ 追加 */

    public function test_画像をアップロードすると末尾に追加される(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);

        $res = $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('cover.png'),
        ]);

        $res->assertCreated()
            ->assertJsonPath('page.page_no', 1)
            ->assertJsonPath('page.img_path', '/books/sample/page-01.png')
            ->assertJsonPath('pages_count', 1);

        Storage::disk('cdn')->assertExists('books/sample/page-01.png');
        $this->assertSame(1, $book->fresh()->pages_count);
    }

    public function test_2枚目は次のページ番号になる(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);

        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('a.png'),
        ])->assertCreated();

        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('b.png'),
        ])
            ->assertCreated()
            ->assertJsonPath('page.page_no', 2)
            ->assertJsonPath('pages_count', 2);
    }

    /* ------------------------------------------------------------ 差し替え */

    public function test_ページ番号を指定すると差し替えになる(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);
        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('first.png'),
        ]);

        $res = $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('second.png'),
            'page_no' => 1,
        ]);

        // 差し替えなので 201 ではなく 200、ページは増えない
        $res->assertOk()->assertJsonPath('pages_count', 1);
        $this->assertSame(1, $book->pages()->count());
    }

    public function test_差し替えるとキャッシュバスターが変わる(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);
        $first = $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('first.png'),
        ])->json('page.url');

        // updated_at は秒単位なので、変化が分かるよう時間を進める
        $this->travel(2)->seconds();

        $second = $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('second.png'),
            'page_no' => 1,
        ])->json('page.url');

        // 同じパスでも ?v= が変わらないと、CDN の 1年 immutable で古い画像が残り続ける
        $this->assertNotSame($first, $second);
    }

    public function test_拡張子が変わる差し替えでは古いファイルを消す(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);
        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('first.png'),
        ]);
        Storage::disk('cdn')->assertExists('books/sample/page-01.png');

        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('second.jpg'),
            'page_no' => 1,
        ])->assertOk()->assertJsonPath('page.img_path', '/books/sample/page-01.jpg');

        Storage::disk('cdn')->assertMissing('books/sample/page-01.png');
        Storage::disk('cdn')->assertExists('books/sample/page-01.jpg');
    }

    /* ------------------------------------------------------ バリデーション */

    public function test_画像以外は受け付けない(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);

        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->create('note.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame(0, $book->pages()->count());
    }

    public function test_大きすぎる画像は受け付けない(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);

        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->create('big.png', 9000, 'image/png'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_画像が無ければ422(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);

        $this->postJson(route('admin.books.pages.store', $book), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    /* ------------------------------------------------------------ 削除 */

    public function test_ページを削除すると画像も消える(): void
    {
        $book = Book::factory()->create(['code' => 'sample']);
        $this->postJson(route('admin.books.pages.store', $book), [
            'image' => UploadedFile::fake()->image('a.png'),
        ]);
        $page = $book->pages()->firstOrFail();

        $this->deleteJson(route('admin.books.pages.destroy', [$book, $page]))
            ->assertOk()
            ->assertJsonPath('pages_count', 0);

        Storage::disk('cdn')->assertMissing('books/sample/page-01.png');
        $this->assertSame(0, $book->pages()->count());
        $this->assertSame(0, $book->fresh()->pages_count);
    }

    public function test_別の書籍のページは削除できない(): void
    {
        $owner = Book::factory()->withPages(1)->create(['code' => 'owner']);
        $other = Book::factory()->create(['code' => 'other']);
        $page = $owner->pages()->firstOrFail();

        // URL を差し替えても、持ち主が違えば 404
        $this->deleteJson(route('admin.books.pages.destroy', [$other, $page]))
            ->assertNotFound();

        $this->assertSame(1, $owner->pages()->count());
    }
}
