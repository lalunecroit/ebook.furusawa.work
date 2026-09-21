<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * books テーブル。
 *
 * 書誌情報を持つ。ページ画像そのものは books_pages 側に持たせる。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            // 書籍コード。URL や管理画面での識別子。
            // 一意性は下の is_active との複合 unique で担保する。
            $table->string('code', 64);

            $table->string('title');
            $table->text('description')->nullable();

            // 総ページ数。books_pages を数えれば出せるが、一覧で毎回
            // COUNT を打たずに済むようにここへ持たせる。
            $table->unsignedInteger('pages')->default(0);

            // 公開日時。null = 未公開、未来日時 = 公開予約。
            // 「公開済みの本を新しい順に」が一覧の基本クエリなので索引を張る。
            $table->timestamp('published_at')->nullable()->index();

            // 論理削除 (deleted_at)。Model 側で SoftDeletes を use する。
            $table->softDeletes();
            $table->timestamps();

            // 生存中 = 1 / 削除済み = NULL を deleted_at から自動導出する生成列。
            // アプリ側から値を入れる必要はなく、SoftDeletes をそのまま使える。
            $table->unsignedTinyInteger('is_active')
                ->storedAs('CASE WHEN deleted_at IS NULL THEN 1 END');

            // 「生存行の code は一意、削除済みは同じ code が何件でも OK」を表現する。
            //
            // SQL では NULL を含む行が unique の判定対象外になる性質を逆手に取っている:
            //   生存行   -> (code, 1)    で比較され重複を弾く
            //   削除済み -> (code, NULL) なので判定されない
            //
            // deleted_at をそのまま複合 unique に含めると NULL 同士が別物扱いになり、
            // 生存行の重複を一切弾けないので注意。
            $table->unique(['code', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
