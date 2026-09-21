<?php

use App\Http\Controllers\Api\BookController;
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

Route::get('/books/{code}', [BookController::class, 'show']);
Route::get('/books/{code}/pages', [BookController::class, 'pages']);
