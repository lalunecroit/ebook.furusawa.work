<?php

namespace App\Http\Resources;

use App\Models\BookPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 本文1ページ分の表現。
 *
 * img_path は DB に入っている相対パス、url はブラウザがそのまま使える完成形。
 * 両方返すのは、管理画面など「保存されている値」が要る画面のため。
 *
 * @mixin BookPage
 */
class BookPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'page_no' => $this->page_no,
            'img_path' => $this->img_path,
            'updated_at' => $this->updated_at->toIso8601String(),
            'url' => $this->url(),
        ];
    }

    /**
     * 画像URLを組み立てる。
     *
     * CDN 側は 1年 + immutable でキャッシュさせているため、同じ URL のままでは
     * 画像を差し替えてもブラウザが取りに来ない。そこで updated_at を ?v= に載せ、
     * 更新されたページだけ URL が変わるようにしている。
     * (API 自体は Cache-Control: no-cache なので、新しい ?v= は次のリロードで届く)
     */
    private function url(): string
    {
        return config('cdn.base_url').$this->img_path.'?v='.$this->updated_at->getTimestamp();
    }
}
