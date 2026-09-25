<?php

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ヘルスチェックの挙動を固定するテスト。
 *
 * 肝は「浅い確認は DB が落ちていても 200 を返す」こと。
 * LB がこれを見ているので、DB の不調で全インスタンスを不健全と判定させない。
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_浅い確認は_200_を返す(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'env', 'time']);
    }

    public function test_浅い確認は_db_に触らない(): void
    {
        // DB が落ちている状況を作る。触ったら例外が出るので、
        // 200 が返ればアクセスしていないことになる。
        DB::shouldReceive('select')->never();

        $this->getJson('/api/health')->assertOk();
    }

    public function test_deep_を付けると_db_まで確認する(): void
    {
        $this->getJson('/api/health?deep=1')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok');
    }

    public function test_deep_で_db_に繋がらなければ_503(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new QueryException('mysql', 'select 1', [], new \RuntimeException('connection refused')));

        $this->getJson('/api/health?deep=1')
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('database', 'error');
    }

    public function test_失敗時に接続情報を漏らさない(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new QueryException(
                'mysql',
                'select 1',
                [],
                new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user ebooks@10.0.0.5'),
            ));

        $body = $this->getJson('/api/health?deep=1')->assertStatus(503)->content();

        $this->assertStringNotContainsString('ebooks', $body);
        $this->assertStringNotContainsString('10.0.0.5', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }
}
