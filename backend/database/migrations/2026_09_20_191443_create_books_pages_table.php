<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * books_pages テーブル。
 *
 * 1 レコード = 本文 1 ページ分の画像。
 * img_path は CDN のドキュメントルートからの相対パス (例: /books/sample/page-01.svg)。
 * ベースURL は config/cdn.php 側で持つので、ここにホスト名は入れない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('books_pages', function (Blueprint $table) {
            $table->id();

            // 親の本。本ごと消したらページも消す。
            $table->foreignId('books_id')->constrained('books')->cascadeOnDelete();

            $table->unsignedInteger('page_no');
            $table->string('img_path');

            // updated_at は画像URLのキャッシュバスター (?v=) の元ネタも兼ねる。
            // 画像を差し替えたらこの値が動き、URL が変わってブラウザが取り直す。
            // DB 側にも既定値を持たせ、生 SQL で差し替えても updated_at が動くようにする。
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // 同じ本に同じページ番号は 1 つだけ。
            // ページ順の取得 (where books_id = ? order by page_no) にもこの索引が効く。
            $table->unique(['books_id', 'page_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books_pages');
    }
};
