<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 管理画面はログイン必須。認証そのものの検証は AuthTest で行う
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'code' => 'new-book',
            'title' => '新しい本',
            'description' => '説明',
            'published_at' => '2026-09-22T10:00',
        ], $overrides);
    }

    /* ---------------------------------------------------------------- 一覧 */

    public function test_一覧には未公開と公開予約も出る(): void
    {
        Book::factory()->create(['code' => 'published', 'published_at' => '2026-01-01 00:00:00']);
        Book::factory()->unpublished()->create(['code' => 'draft']);
        Book::factory()->create(['code' => 'scheduled', 'published_at' => now()->addDay()]);

        // 公開側の一覧 API と違い、管理画面は状態を問わず全件見せる
        $this->get(route('admin.books.index'))
            ->assertOk()
            ->assertSee('published')
            ->assertSee('draft')
            ->assertSee('scheduled')
            ->assertSee('未公開')
            ->assertSee('公開予約')
            ->assertSee('公開中');
    }

    public function test_一覧に論理削除済みは出ない(): void
    {
        Book::factory()->create(['code' => 'alive']);
        Book::factory()->trashed()->create(['code' => 'deleted-one']);

        $this->get(route('admin.books.index'))
            ->assertOk()
            ->assertSee('alive')
            ->assertDontSee('deleted-one');
    }

    public function test_一覧にページ数が出る(): void
    {
        Book::factory()->withPages(3)->create(['code' => 'with-pages']);

        $this->get(route('admin.books.index'))
            ->assertOk()
            ->assertSee('with-pages');
    }

    /* ---------------------------------------------------------------- 登録 */

    public function test_新規登録フォームが表示される(): void
    {
        $this->get(route('admin.books.create'))->assertOk();
    }

    public function test_書籍を登録できる(): void
    {
        $res = $this->post(route('admin.books.store'), $this->validInput());

        $book = Book::where('code', 'new-book')->firstOrFail();

        $res->assertRedirect(route('admin.books.edit', $book))
            ->assertSessionHas('status');

        $this->assertSame('新しい本', $book->title);
        $this->assertSame('2026-09-22 10:00:00', $book->published_at->format('Y-m-d H:i:s'));
    }

    public function test_公開日時を空にすると未公開で登録される(): void
    {
        $this->post(route('admin.books.store'), $this->validInput(['published_at' => '']));

        $this->assertNull(Book::where('code', 'new-book')->firstOrFail()->published_at);
    }

    public function test_登録時に必須項目を検証する(): void
    {
        $this->post(route('admin.books.store'), ['code' => '', 'title' => ''])
            ->assertSessionHasErrors(['code', 'title']);

        $this->assertSame(0, Book::count());
    }

    public function test_書籍コードの形式を検証する(): void
    {
        $this->post(route('admin.books.store'), $this->validInput(['code' => 'Bad_Code!']))
            ->assertSessionHasErrors('code');

        $this->assertSame(0, Book::count());
    }

    public function test_生存している書籍とコードが重複したら弾く(): void
    {
        Book::factory()->create(['code' => 'taken']);

        $this->post(route('admin.books.store'), $this->validInput(['code' => 'taken']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Book::count());
    }

    public function test_削除済みの書籍とはコードが重複してよい(): void
    {
        // (code, is_active) の部分ユニークと同じ扱いを、フォーム側でも再現している
        Book::factory()->trashed()->create(['code' => 'reusable']);

        $this->post(route('admin.books.store'), $this->validInput(['code' => 'reusable']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Book::where('code', 'reusable')->count());
    }

    public function test_fillableにない項目は無視される(): void
    {
        // is_active は生成列。送られても書き込まれない (書き込むと DB エラーになる)
        $this->post(route('admin.books.store'), $this->validInput([
            'is_active' => 1,
            'pages_count' => 999,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, Book::where('code', 'new-book')->firstOrFail()->pages_count);
    }

    /* ---------------------------------------------------------------- 更新 */

    public function test_編集フォームに現在の値が入る(): void
    {
        $book = Book::factory()->create(['code' => 'editing', 'title' => '編集前']);

        $this->get(route('admin.books.edit', $book))
            ->assertOk()
            ->assertSee('editing')
            ->assertSee('編集前');
    }

    public function test_存在しない書籍の編集は404(): void
    {
        $this->get(route('admin.books.edit', 'no-such-book'))->assertNotFound();
    }

    public function test_論理削除済みの書籍の編集は404(): void
    {
        $book = Book::factory()->trashed()->create(['code' => 'gone']);

        $this->get(route('admin.books.edit', $book->code))->assertNotFound();
    }

    public function test_書籍を更新できる(): void
    {
        $book = Book::factory()->create(['code' => 'editing', 'title' => '編集前']);

        $this->put(route('admin.books.update', $book), $this->validInput([
            'code' => 'editing',
            'title' => '編集後',
        ]))
            ->assertRedirect(route('admin.books.edit', $book))
            ->assertSessionHas('status');

        $this->assertSame('編集後', $book->fresh()->title);
    }

    public function test_更新時は自分自身のコードを重複とみなさない(): void
    {
        $book = Book::factory()->create(['code' => 'keep-code']);

        $this->put(route('admin.books.update', $book), $this->validInput(['code' => 'keep-code']))
            ->assertSessionHasNoErrors();
    }

    public function test_更新時も他の書籍とのコード重複は弾く(): void
    {
        Book::factory()->create(['code' => 'other']);
        $book = Book::factory()->create(['code' => 'mine']);

        $this->put(route('admin.books.update', $book), $this->validInput(['code' => 'other']))
            ->assertSessionHasErrors('code');

        $this->assertSame('mine', $book->fresh()->code);
    }

    public function test_公開日時を空にすると未公開に戻せる(): void
    {
        $book = Book::factory()->create(['code' => 'unpublish-me']);

        $this->put(route('admin.books.update', $book), $this->validInput([
            'code' => 'unpublish-me',
            'published_at' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertNull($book->fresh()->published_at);
    }

    /* ---------------------------------------------------------------- 入口 */

    public function test_adminのトップは一覧へリダイレクトする(): void
    {
        $this->get('/admin')->assertRedirect('/admin/books');
    }
}
