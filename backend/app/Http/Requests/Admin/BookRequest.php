<?php

namespace App\Http\Requests\Admin;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 管理画面の書籍フォーム。新規登録と編集で同じ規則を使う。
 */
class BookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Book|null $book 編集中の書籍 (新規なら null) */
        $book = $this->route('book');

        return [
            // URL に出る識別子。生存行の中で一意
            // (削除済みとは重複してよい。books_code_is_active_unique と同じ考え方)
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('books', 'code')
                    ->whereNull('deleted_at')
                    ->ignore($book),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            // null = 未公開、未来日時 = 公開予約
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => '書籍コード',
            'title' => '書名',
            'description' => '説明',
            'published_at' => '公開日時',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => ':attribute は半角英数字とハイフンで入力してください (例: laravel-api)。',
        ];
    }
}
