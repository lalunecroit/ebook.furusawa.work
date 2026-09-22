<?php

use App\Http\Controllers\Admin\BookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 管理画面のルート
|--------------------------------------------------------------------------
|
| bootstrap/app.php の withRouting(then: ...) で読み込まれ、
| すべて /admin プレフィックス・admin. 名前空間・web ミドルウェア配下になる。
|
| 認証はまだ入れていない。auth を足す段階では、この group にまとめて
| middleware('auth') を付けられるよう、web.php とはファイルを分けてある。
|
*/

Route::redirect('/', '/admin/books')->name('home');

Route::get('/books', [BookController::class, 'index'])->name('books.index');
Route::get('/books/create', [BookController::class, 'create'])->name('books.create');
Route::post('/books', [BookController::class, 'store'])->name('books.store');
Route::get('/books/{book}/edit', [BookController::class, 'edit'])->name('books.edit');
Route::put('/books/{book}', [BookController::class, 'update'])->name('books.update');
