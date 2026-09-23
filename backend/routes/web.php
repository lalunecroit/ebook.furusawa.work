<?php

use Illuminate\Support\Facades\Route;

// adminホストは、管理画面パス(/admin/books)へリダイレクト
if ($adminHost = config('app.admin_host')) {
    Route::domain($adminHost)->group(function (): void {
        Route::redirect('/', '/admin/books');
    });
}

Route::get('/', function () {
    return view('welcome');
});
