@extends('admin.layout')

@section('title', '書籍の新規登録')

@section('content')
  <h2>書籍の新規登録</h2>

  <form method="POST" action="{{ route('admin.books.store') }}">
    @csrf
    @include('admin.books._form')

    <div class="actions">
      <button type="submit" class="btn btn--primary">登録する</button>
      <a href="{{ route('admin.books.index') }}" class="btn">キャンセル</a>
    </div>
  </form>
@endsection
