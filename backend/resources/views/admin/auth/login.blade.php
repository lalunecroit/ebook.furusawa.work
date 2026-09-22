@extends('admin.layout')

@section('title', 'ログイン')

@section('content')
  <div class="login">
    <h2>管理画面にログイン</h2>

    <form method="POST" action="{{ route('admin.login.store') }}">
      @csrf

      <div class="field">
        <label for="email">メールアドレス</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}"
               required autofocus autocomplete="username">
        @error('email') <p class="field__error">{{ $message }}</p> @enderror
      </div>

      <div class="field">
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        @error('password') <p class="field__error">{{ $message }}</p> @enderror
      </div>

      <div class="actions">
        <button type="submit" class="btn btn--primary">ログイン</button>
      </div>
    </form>
  </div>

  <style>
    .login { max-width: 380px; margin: 40px auto 0; }
  </style>
@endsection
