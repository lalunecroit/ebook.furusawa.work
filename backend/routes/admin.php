<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BookController;
use App\Http\Controllers\Admin\BookPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 管理画面のルート
|--------------------------------------------------------------------------
|
| bootstrap/app.php の withRouting(then: ...) で読み込まれ、
| すべて /admin プレフィックス・admin. 名前空間・web ミドルウェア配下になる。
|
| ログイン画面だけは未ログインで開ける必要があるので、auth はグループ全体
| (bootstrap 側) ではなく、このファイルの中で機能ごとに付けている。
|
*/

// 未ログイン専用。ログイン済みで開くと一覧へ戻す (bootstrap/app.php の redirectUsersTo)
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

// ここから下はログインが必要。未ログインならログイン画面へ (redirectGuestsTo)
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/admin/books')->name('home');

    Route::get('/books', [BookController::class, 'index'])->name('books.index');
    Route::get('/books/create', [BookController::class, 'create'])->name('books.create');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    Route::get('/books/{book}/edit', [BookController::class, 'edit'])->name('books.edit');
    Route::put('/books/{book}', [BookController::class, 'update'])->name('books.update');

    // ページ画像。アップロードと削除は画面から fetch で叩くので JSON を返す
    Route::get('/books/{book}/pages', [BookPageController::class, 'index'])->name('books.pages.index');
    Route::post('/books/{book}/pages', [BookPageController::class, 'store'])->name('books.pages.store');
    Route::delete('/books/{book}/pages/{page}', [BookPageController::class, 'destroy'])->name('books.pages.destroy');
});
