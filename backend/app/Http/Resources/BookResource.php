<?php

namespace App\Http\Resources;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 書誌情報 + ページ一覧。ビューアはこれ1本で起動できる。
 *
 * @mixin Book
 */
class BookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'published_at' => $this->published_at?->toIso8601String(),
            // books.pages_count ではなく実際の行数を返す。pages_count は一覧表示用の
            // 非正規化カラムで、ページを差し替えた直後などにズレうるため。
            'total_pages' => $this->pages->count(),
            'pages' => BookPageResource::collection($this->pages),
        ];
    }
}
