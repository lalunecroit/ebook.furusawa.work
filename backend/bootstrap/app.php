<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // 管理画面。/admin 配下・admin. 名前・web ミドルウェア (セッションと CSRF) で束ねる。
            // 認証 (auth / guest) はログイン画面を除外する必要があるので routes/admin.php 側で付ける。
            $admin = Route::middleware('web')->prefix('admin')->name('admin.');

            // ホストを限定する。api. と admin. は同じイメージの別サービスなので、
            // 限定しないと api.ebook.furusawa.work/admin/... でも管理画面に届く。
            // 開発は api も admin も localhost で兼ねているため ADMIN_HOST を空にして無効化する。
            if ($adminHost = config('app.admin_host')) {
                $admin->domain($adminHost);
            }

            $admin->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // LB / nginx の後ろで動くので、転送ヘッダを信頼しないと生成される URL が
        // http:// のままになる (LB が TLS を終端し、中は平文で届くため)。
        //
        // プロキシは限定せず '*'。Cloud Run は Google のフロントエンド経由でしか
        // 到達できず、開発でも必ず backend-web (nginx) を挟むので、直接届く経路が無い。
        //
        // X-Forwarded-For はあえて信頼しない。信頼するとクライアントが自分の IP を
        // 自由に名乗れる。ログインのレート制限は LoginRequest::throttleKey() が
        // email|ip をキーにしているため、IP を変えるだけで総当たり対策を回避できてしまう。
        // (Google の LB は受け取った X-Forwarded-For の右側に実 IP を足す形なので、
        //  左端を実 IP とみなす Symfony の既定とは噛み合わない)
        // 信頼しなければ $request->ip() はプロキシの IP になる。これは今の挙動と同じ。
        //
        // X-Forwarded-Host も信頼しない。Cloud Run も開発の nginx も元の Host を
        // そのまま渡すので不要で、信頼すると生成 URL のホストを外から差し替えられる。
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );

        // セッション認証を使うのは管理画面だけなので、行き先も管理画面に固定する。
        //   未ログインで auth の付いたページを開いた → ログイン画面へ
        //   ログイン済みで guest の付いたページを開いた → 書籍一覧へ
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.books.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
