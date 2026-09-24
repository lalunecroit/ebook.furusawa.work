<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * リバースプロキシ配下での転送ヘッダの扱いを固定するテスト。
 *
 * LB が TLS を終端してコンテナには平文で届くため、X-Forwarded-Proto を
 * 信頼しないと生成される URL が http:// のままになる。
 * 一方で信頼しすぎると外から値を差し込めるので、信頼する範囲を明示的に押さえる。
 */
class TrustProxiesTest extends TestCase
{
    /** リクエストの解釈結果を覗くためのテスト専用ルート */
    private function probeRoute(): void
    {
        Route::get('/__probe', fn () => response()->json([
            'scheme' => request()->getScheme(),
            'host' => request()->getHost(),
            'ip' => request()->ip(),
            'url' => url('/__probe'),
        ]));
    }

    public function test_x_forwarded_proto_を信頼して_https_の_url_を生成する(): void
    {
        $this->probeRoute();

        $this->getJson('/__probe', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJsonPath('scheme', 'https');
    }

    public function test_転送ヘッダが無ければ_http_のまま(): void
    {
        $this->probeRoute();

        $this->getJson('/__probe')
            ->assertOk()
            ->assertJsonPath('scheme', 'http');
    }

    public function test_x_forwarded_host_は信頼しない(): void
    {
        // 信頼すると生成URLのホストを外から差し替えられる。
        // Cloud Run も開発の nginx も元の Host をそのまま渡すので不要。
        $this->probeRoute();

        $res = $this->getJson('/__probe', ['X-Forwarded-Host' => 'evil.example'])->assertOk();

        $this->assertNotSame('evil.example', $res->json('host'));
        $this->assertStringNotContainsString('evil.example', (string) $res->json('url'));
    }

    public function test_x_forwarded_for_は信頼しない(): void
    {
        // 信頼するとクライアントが自分の IP を名乗れてしまい、
        // email|ip をキーにしているログインのレート制限を回避できる
        // (LoginRequest::throttleKey)。
        $this->probeRoute();

        $res = $this->getJson('/__probe', ['X-Forwarded-For' => '203.0.113.9'])->assertOk();

        $this->assertNotSame('203.0.113.9', $res->json('ip'));
    }
}
