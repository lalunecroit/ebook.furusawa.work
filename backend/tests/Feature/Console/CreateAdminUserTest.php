<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_管理者を作成できる(): void
    {
        $this->artisan('admin:user', ['email' => 'admin@example.com', '--name' => '管理者'])
            ->expectsQuestion('パスワード (8文字以上)', 'correct-password')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('管理者', $user->name);

        // 平文のまま保存されていないこと
        $this->assertNotSame('correct-password', $user->password);
        $this->assertTrue(Hash::check('correct-password', $user->password));
    }

    public function test_名前を省略するとメールアドレスの前半になる(): void
    {
        $this->artisan('admin:user', ['email' => 'editor@example.com'])
            ->expectsQuestion('パスワード (8文字以上)', 'correct-password')
            ->assertSuccessful();

        $this->assertSame('editor', User::where('email', 'editor@example.com')->value('name'));
    }

    public function test_短いパスワードは受け付けない(): void
    {
        $this->artisan('admin:user', ['email' => 'admin@example.com'])
            ->expectsQuestion('パスワード (8文字以上)', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_既存のメールアドレスは受け付けない(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->artisan('admin:user', ['email' => 'admin@example.com'])
            ->expectsQuestion('パスワード (8文字以上)', 'correct-password')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }
}
