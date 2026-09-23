<?php

use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API ルート
|--------------------------------------------------------------------------
|
| bootstrap/app.php の withRouting(api: ...) で読み込まれ、
| すべて /api プレフィックス配下になる。
| 認証は入れていない (auth を足す段階で middleware を付ける)。
|
*/

// 監視と疎通確認用。既定は DB に触らない浅い確認で、?deep=1 で DB まで見る。
Route::get('/health', HealthController::class);

Route::get('/books', [BookController::class, 'index']);
Route::get('/books/{book}', [BookController::class, 'show']);
Route::get('/books/{book}/pages', [BookController::class, 'pages']);
