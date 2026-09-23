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
            'url' => $this->url,
        ];
    }
}
