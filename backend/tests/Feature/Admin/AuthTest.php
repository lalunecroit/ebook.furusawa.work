<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);
    }

    /* ------------------------------------------------ 未ログイン */

    public function test_ログイン画面は未ログインで開ける(): void
    {
        $this->get(route('admin.login'))->assertOk()->assertSee('管理画面にログイン');
    }

    public function test_未ログインで管理画面を開くとログイン画面へ(): void
    {
        $book = Book::factory()->create();

        foreach ([
            route('admin.home'),
            route('admin.books.index'),
            route('admin.books.create'),
            route('admin.books.edit', $book),
            route('admin.books.pages.index', $book),
        ] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
    }

    public function test_未ログインでは登録も更新もできない(): void
    {
        $book = Book::factory()->create(['code' => 'keep', 'title' => '元の書名']);

        $this->post(route('admin.books.store'), ['code' => 'intruder', 'title' => '不正'])
            ->assertRedirect(route('admin.login'));
        $this->put(route('admin.books.update', $book), ['code' => 'keep', 'title' => '改ざん'])
            ->assertRedirect(route('admin.login'));

        $this->assertFalse(Book::where('code', 'intruder')->exists());
        $this->assertSame('元の書名', $book->fresh()->title);
    }

    public function test_未ログインでfetchから叩くと401(): void
    {
        // 画面の JS は Accept: application/json で送るので、リダイレクトではなく 401 が返る
        $book = Book::factory()->create();

        $this->postJson(route('admin.books.pages.store', $book), [])->assertUnauthorized();
    }

    /* ------------------------------------------------ ログイン */

    public function test_正しいパスワードでログインできる(): void
    {
        $admin = $this->admin();

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.books.index'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_ログイン後は開こうとしていたページへ戻る(): void
    {
        $this->admin();
        $book = Book::factory()->create();

        // 先に保護されたページを開いてログイン画面へ飛ばされる
        $this->get(route('admin.books.pages.index', $book));

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ])->assertRedirect(route('admin.books.pages.index', $book));
    }

    public function test_誤ったパスワードではログインできない(): void
    {
        $this->admin();

        $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_存在しないメールアドレスでも同じエラーを返す(): void
    {
        $this->admin();
        $message = 'メールアドレスまたはパスワードが正しくありません。';

        // どちらが違うのかを区別できると、登録済みのメールアドレスを探られてしまう
        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com', 'password' => 'wrong',
        ])->assertSessionHasErrors(['email' => $message]);

        $this->post(route('admin.login.store'), [
            'email' => 'nobody@example.com', 'password' => 'wrong',
        ])->assertSessionHasErrors(['email' => $message]);
    }

    public function test_ログインでセッションidが作り直される(): void
    {
        $this->admin();
        $this->get(route('admin.login'));
        $before = session()->getId();

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        // 同じ ID を使い続けると、事前に仕込まれた ID でなりすまされる (セッション固定)
        $this->assertNotSame($before, session()->getId());
    }

    public function test_5回失敗すると正しいパスワードでもしばらく入れない(): void
    {
        $this->admin();

        foreach (range(1, 5) as $i) {
            $this->post(route('admin.login.store'), [
                'email' => 'admin@example.com', 'password' => "wrong-{$i}",
            ]);
        }

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_ログインに成功すると失敗回数がリセットされる(): void
    {
        $this->admin();

        foreach (range(1, 4) as $i) {
            $this->post(route('admin.login.store'), [
                'email' => 'admin@example.com', 'password' => "wrong-{$i}",
            ]);
        }
        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com', 'password' => 'correct-password',
        ]);

        $this->assertSame(0, RateLimiter::attempts('admin@example.com|127.0.0.1'));
    }

    public function test_ログイン済みでログイン画面を開くと一覧へ(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.books.index'));
    }

    /* ------------------------------------------------ ログアウト */

    public function test_ログアウトできる(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
        $this->get(route('admin.books.index'))->assertRedirect(route('admin.login'));
    }

    public function test_getではログアウトできない(): void
    {
        // リンクや画像タグ経由で勝手にログアウトさせられないよう POST のみ
        $this->actingAs($this->admin())
            ->get('/admin/logout')
            ->assertMethodNotAllowed();

        $this->assertAuthenticated();
    }

    /* ------------------------------------------------ 公開側 */

    public function test_公開apiは認証の影響を受けない(): void
    {
        Book::factory()->withPages(1)->create(['code' => 'sample']);

        $this->getJson('/api/books')->assertOk();
        $this->getJson('/api/books/sample')->assertOk();
    }
}
