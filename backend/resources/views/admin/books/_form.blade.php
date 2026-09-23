{{--
  新規登録と編集で共有するフォーム。
  old() を挟んでいるのは、バリデーションで戻されたときに入力を残すため。
--}}
<div class="field">
  <label for="code">書籍コード</label>
  <input type="text" id="code" name="code" value="{{ old('code', $book->code) }}" required>
  <p class="field__hint">URL に出る識別子（例: <code>laravel-api</code>）。画像の置き場所 <code>/books/&lt;コード&gt;/</code> とも対応します。</p>
  @error('code') <p class="field__error">{{ $message }}</p> @enderror
</div>

<div class="field">
  <label for="title">書名</label>
  <input type="text" id="title" name="title" value="{{ old('title', $book->title) }}" required>
  @error('title') <p class="field__error">{{ $message }}</p> @enderror
</div>

<div class="field">
  <label for="description">説明</label>
  <textarea id="description" name="description">{{ old('description', $book->description) }}</textarea>
  @error('description') <p class="field__error">{{ $message }}</p> @enderror
</div>

<div class="field">
  <label for="published_at">公開日時</label>
  <input type="datetime-local" id="published_at" name="published_at"
         value="{{ old('published_at', $book->published_at?->format('Y-m-d\TH:i')) }}">
  <p class="field__hint">空欄なら未公開。未来の日時を入れると公開予約になり、その時刻まで一覧 API に出ません。</p>
  @error('published_at') <p class="field__error">{{ $message }}</p> @enderror
</div>
