@extends('admin.layout')

@section('title', $book->title . ' の編集')

@section('content')
  <h2>書籍の編集</h2>

  <form method="POST" action="{{ route('admin.books.update', $book) }}">
    @csrf
    {{-- HTML のフォームは PUT を送れないので、Laravel の method spoofing を使う --}}
    @method('PUT')
    @include('admin.books._form')

    <div class="actions">
      <button type="submit" class="btn btn--primary">更新する</button>
      <a href="{{ route('admin.books.index') }}" class="btn">一覧へ戻る</a>
      <span style="color:var(--muted);font-size:13px">
        ページ数 {{ $book->pages_count }}／登録 {{ $book->created_at?->format('Y-m-d H:i') }}
      </span>
    </div>
  </form>
@endsection
