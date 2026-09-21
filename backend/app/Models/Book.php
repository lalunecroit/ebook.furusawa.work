<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * books テーブル。
 *
 * 書誌情報のみを持ち、ページ画像は BookPage 側にある。
 *
 * is_active は deleted_at から導出される生成列なので fillable に入れない。
 * 値を入れようとすると DB 側に弾かれる。
 */
#[Fillable(['code', 'title', 'description', 'pages_count', 'published_at'])]
class Book extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pages_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * 本文ページ。
     *
     * 関連名は books.pages_count に合わせてある。こう名付けておくと
     * withCount('pages') の結果が同じ属性名に入り、カラムを持つ場合と
     * COUNT する場合とで参照側の書き方が変わらない。
     *
     * @return HasMany<BookPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class);
    }
}
