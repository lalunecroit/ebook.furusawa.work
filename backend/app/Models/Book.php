<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * ルートモデルバインディングは id ではなく code で解決する (#[RouteKey])。
 * ルート側は {book} と書くだけでよく、404 と論理削除済みの除外は
 * フレームワークが面倒を見る。
 */
#[Fillable(['code', 'title', 'description', 'pages_count', 'published_at'])]
#[RouteKey('code')]
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
     * ルートモデルバインディングの検索条件。
     *
     * 既定では SoftDeletes のグローバルスコープが付ける `deleted_at is null` だけで
     * 絞られるが、それでは複合ユニーク (code, is_active) の code 側しか索引に効かず、
     * type=ref + Using where (deleted_at は行を読んでから判定) になる。
     *
     * 生存行は is_active = 1 と同値なので、これを条件に足して索引を端まで使う。
     * type=const の一意検索になり、deleted_at 側の条件は取得済みの行に対する
     * 追加判定になるだけでコストはかからない。
     *
     * 削除済みも引きたいルート (->withTrashed()) は resolveSoftDeletableRouteBinding
     * を通るため、こちらの条件は適用されない。
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // 親の戻り値の宣言は Contracts\...\Builder (where() を宣言していないインターフェース)
        // なので、実体である Eloquent のビルダであることを注釈しておく。
        /** @var Builder<static> $query */
        $query = $this->resolveRouteBindingQuery($this, $value, $field);

        return $query->where('is_active', 1)->first();
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
