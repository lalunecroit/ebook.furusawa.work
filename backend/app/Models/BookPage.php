<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * book_pages テーブル。
 *
 * 1 レコード = 本文 1 ページ分の画像。
 * img_path は CDN のドキュメントルートからの相対パスで、ホスト名は含まない。
 * 表示用の URL は $page->url で取れる (下の url アクセサ)。
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
     * ブラウザがそのまま使える画像URL。
     *
     * CDN 側は 1年 + immutable でキャッシュさせているため、同じ URL のままでは
     * 画像を差し替えてもブラウザが取りに来ない。そこで updated_at を ?v= に載せ、
     * 更新されたページだけ URL が変わるようにしている。
     * (API 自体は Cache-Control: no-cache なので、新しい ?v= は次のリロードで届く)
     *
     * API・管理画面・Blade の3箇所で同じ式を書いていたのでここへ集約した。
     * $appends には入れていない。出力に含めるかは呼ぶ側 (Resource など) が決める。
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn (): string => config('cdn.base_url')
                .$this->img_path
                .'?v='.$this->updated_at->getTimestamp(),
        );
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
