<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * book_pages テーブル。
 *
 * 1 レコード = 本文 1 ページ分の画像。
 * img_path は CDN のドキュメントルートからの相対パスで、ホスト名は含まない。
 * 表示用の URL は config('cdn.base_url') と組み合わせて作る。
 */
#[Fillable(['book_id', 'page_no', 'img_path'])]
class BookPage extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_no' => 'integer',
        ];
    }

    /**
     * 親の本。
     *
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
