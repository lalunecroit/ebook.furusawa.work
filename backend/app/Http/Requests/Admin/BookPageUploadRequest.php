<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ページ画像のアップロード。1リクエストにつき1枚。
 */
class BookPageUploadRequest extends FormRequest
{
    /** 受け付ける拡張子。SVG は生成ツールが出す形式なので含めている */
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

    /**
     * 1枚あたりの上限 (KB)。利用者に見えるのはこの値。
     *
     * 外側の層 (PHP の upload_max_filesize / post_max_size、nginx の
     * client_max_body_size、Cloud Run の 32MiB) は、すべてこれより大きく
     * しておく必要がある。外側で先に落ちると、下の image.max の日本語ではなく
     * PHP や nginx の汎用エラーが出てしまう。
     * 段の全体は docker/prod/php.ini のコメントにまとめてある。
     */
    public const MAX_KB = 8192;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimes:'.implode(',', self::ALLOWED),
                'max:'.self::MAX_KB,
            ],
            // 省略時は末尾に追加する。既存のページ番号を指定すると差し替えになる
            'page_no' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image' => '画像',
            'page_no' => 'ページ番号',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.mimes' => '画像は '.implode(' / ', self::ALLOWED).' のいずれかで指定してください。',
            'image.max' => '画像は '.(self::MAX_KB / 1024).'MB 以内にしてください。',
        ];
    }
}
