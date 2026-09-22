<?php

namespace App\Http\Resources;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 一覧に出す1冊分の表現。
 *
 * 詳細 (BookResource) と違い、ページ一覧は含めない。
 * 1冊あたり10枚の URL を全部返すと、20冊で200件になってしまうため。
 *
 * @mixin Book
 */
class BookListResource extends JsonResource
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
            // 一覧では非正規化カラムをそのまま使う。実数を数えると1冊ごとに
            // COUNT が増えるので、この用途のために持たせているカラム。
            'total_pages' => $this->pages_count,
            // 表紙。URL の組み立て (?v=) は BookPageResource に任せる
            'cover' => BookPageResource::make($this->whenLoaded('cover')),
        ];
    }
}
