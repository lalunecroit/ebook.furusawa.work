<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理画面が admin. のホストでしか出ないことを固定するテスト。
 *
 * api. と admin. は同じイメージを別サービスとして動かすので、ホストを限定
 * しないと api.ebook.furusawa.work/admin/... でも管理画面に届いてしまう。
 */
class AdminHostTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_HOST = 'admin.example.test';

    /** null にして refreshApplication() すると、制限なしの状態で起動し直せる */
    private ?string $adminHost = self::ADMIN_HOST;

    /**
     * ルートのホスト制限はアプリの起動時に確定するので、
     * config() を後から書き換えても効かない。起動前に環境変数を入れる。
     */
    public function createApplication()
    {
        if ($this->adminHost === null) {
            putenv('ADMIN_HOST');
            unset($_ENV['ADMIN_HOST'], $_SERVER['ADMIN_HOST']);
        } else {
            putenv('ADMIN_HOST='.$this->adminHost);
            $_ENV['ADMIN_HOST'] = $this->adminHost;
            $_SERVER['ADMIN_HOST'] = $this->adminHost;
        }

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        putenv('ADMIN_HOST');
        unset($_ENV['ADMIN_HOST'], $_SERVER['ADMIN_HOST']);

        parent::tearDown();
    }

    public function test_admin_ホストなら管理画面に届く(): void
    {
        $this->get('http://'.self::ADMIN_HOST.'/admin/login')->assertOk();
    }

    public function test_別のホストからは管理画面に届かない(): void
    {
        foreach (['api.example.test', 'www.example.test'] as $host) {
            $this->get("http://{$host}/admin/login")
                ->assertNotFound();
        }
    }

    public function test_公開APIはホストを問わず届く(): void
    {
        // 制限をかけるのは管理画面だけ。API は www. から叩かれる
        foreach ([self::ADMIN_HOST, 'api.example.test', 'www.example.test'] as $host) {
            $this->getJson("http://{$host}/api/books")->assertOk();
        }
    }

    public function test_設定が空なら制限しない(): void
    {
        // 開発は api も admin も localhost で兼ねるので、空のときは従来どおり通す
        $this->adminHost = null;
        $this->refreshApplication();

        $this->get('http://api.example.test/admin/login')->assertOk();
    }
}
