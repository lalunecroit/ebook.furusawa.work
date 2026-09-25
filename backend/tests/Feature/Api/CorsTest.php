<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORS の許可範囲を固定するテスト。
 *
 * サブドメインで役割を分けたため www. から api. への fetch はクロスオリジンになる。
 * フレームワークの既定は allowed_origins => ['*'] なので、絞れていることを明示的に確認する。
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const WWW = 'https://www.ebook.furusawa.work';

    private const APEX = 'https://ebook.furusawa.work';

    /** 複数オリジンを設定した状態にする (本番の構成) */
    private function allowBothOrigins(): void
    {
        config(['cors.allowed_origins' => [self::WWW, self::APEX]]);
    }

    public function test_許可したオリジンには自分のオリジンが返る(): void
    {
        $this->allowBothOrigins();

        foreach ([self::WWW, self::APEX] as $origin) {
            $this->getJson('/api/books', ['Origin' => $origin])
                ->assertOk()
                ->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_許可していないオリジンにはヘッダを返さない(): void
    {
        $this->allowBothOrigins();

        $res = $this->getJson('/api/books', ['Origin' => 'https://evil.example'])->assertOk();

        $this->assertFalse(
            $res->headers->has('Access-Control-Allow-Origin'),
            '許可していないオリジンに Access-Control-Allow-Origin を返している',
        );
    }

    public function test_複数オリジンのときは_vary_origin_が付く(): void
    {
        // オリジンごとに応答が変わるので、途中のキャッシュが混同しないよう Vary が要る
        $this->allowBothOrigins();

        $res = $this->getJson('/api/books', ['Origin' => self::WWW])->assertOk();

        $this->assertStringContainsString('Origin', (string) $res->headers->get('Vary'));
    }

    public function test_単一オリジン設定ではリクエスト元によらずその値を返す(): void
    {
        // php-cors は許可が1つだけなら固定値を返す (CorsService::isSingleOriginAllowed)。
        // 値がリクエスト元と一致しないのでブラウザ側が弾く。安全side だが、
        // 「ヘッダが無いこと」を期待すると読み違えるのでテストで明示しておく。
        config(['cors.allowed_origins' => [self::WWW]]);

        $this->getJson('/api/books', ['Origin' => 'https://evil.example'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::WWW);
    }

    public function test_プリフライトは参照系のメソッドだけ許可する(): void
    {
        $this->allowBothOrigins();

        $res = $this->call('OPTIONS', '/api/books', [], [], [], [
            'HTTP_ORIGIN' => self::WWW,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $res->assertNoContent();
        $this->assertSame('GET, HEAD, OPTIONS', $res->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame('3600', $res->headers->get('Access-Control-Max-Age'));
    }

    public function test_管理画面はcorsの対象外(): void
    {
        // admin. は自分のページから自分のサーバを叩くので同一オリジン。
        // cors.paths が api/* だけであることの裏取り。
        $this->allowBothOrigins();

        $res = $this->get('/admin/books', ['Origin' => self::WWW]);

        $this->assertFalse(
            $res->headers->has('Access-Control-Allow-Origin'),
            '管理画面が CORS の対象に入っている',
        );
    }
}
