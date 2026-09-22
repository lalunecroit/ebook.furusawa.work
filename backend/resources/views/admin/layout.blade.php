<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', '管理画面') | ebook.furusawa.work</title>
<style>
  /* 配色はビューア (frontend/public/css/style.css) のトークンに合わせている */
  :root {
    --bg: #2a2d31;
    --bg-panel: #33373c;
    --bg-bar: rgba(20, 22, 25, 0.92);
    --fg: #e8eaed;
    --muted: #9aa4af;
    --accent: #6ea0ff;
    --border: #3f454c;
    --danger: #e5836a;
    --ok: #49c2a2;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    background: var(--bg);
    color: var(--fg);
    font-family: system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif;
    line-height: 1.7;
  }

  a { color: var(--accent); }

  .bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    height: 52px;
    padding: 0 20px;
    background: var(--bg-bar);
    border-bottom: 1px solid var(--border);
  }
  .bar__title { margin: 0; font-size: 15px; font-weight: 600; }
  .bar__title a { text-decoration: none; color: var(--fg); }
  .bar__actions { display: flex; align-items: center; gap: 12px; }
  .bar__actions form { margin: 0; }
  .bar__user { font-size: 13px; color: var(--muted); }

  .wrap { max-width: 920px; margin: 0 auto; padding: 24px 20px 64px; }

  h2 { font-size: 18px; margin: 0 0 18px; }

  /* ---------------- 通知 ---------------- */
  .flash {
    margin: 0 0 20px;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-left: 3px solid var(--ok);
    border-radius: 4px;
    background: rgba(73, 194, 162, 0.08);
    font-size: 14px;
  }

  .errors {
    margin: 0 0 20px;
    padding: 12px 14px 12px 32px;
    border: 1px solid var(--border);
    border-left: 3px solid var(--danger);
    border-radius: 4px;
    background: rgba(229, 131, 106, 0.08);
    color: #f0c9c9;
    font-size: 14px;
  }
  .errors li { margin: 2px 0; }

  /* ---------------- 一覧 ---------------- */
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: top;
  }
  th { color: var(--muted); font-weight: 600; font-size: 13px; }
  tbody tr:hover { background: rgba(255, 255, 255, 0.03); }

  .badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 12px;
    border: 1px solid var(--border);
    color: var(--muted);
  }
  .badge--public { border-color: var(--ok); color: var(--ok); }

  .num { font-variant-numeric: tabular-nums; }

  /* ---------------- フォーム ---------------- */
  .field { margin-bottom: 18px; }
  .field label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--muted); }
  .field input,
  .field textarea {
    width: 100%;
    padding: 9px 12px;
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--fg);
    font: inherit;
    font-size: 14px;
  }
  .field input:focus,
  .field textarea:focus { outline: none; border-color: var(--accent); }
  .field textarea { min-height: 90px; resize: vertical; }
  .field__hint { margin: 5px 0 0; font-size: 12px; color: var(--muted); }
  .field__error { margin: 5px 0 0; font-size: 12px; color: var(--danger); }

  .actions { display: flex; gap: 12px; align-items: center; margin-top: 24px; }

  .btn {
    padding: 9px 20px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: transparent;
    color: var(--fg);
    font: inherit;
    font-size: 14px;
    text-decoration: none;
    cursor: pointer;
  }
  .btn:hover { border-color: var(--accent); }
  .btn--primary {
    background: var(--accent);
    border-color: var(--accent);
    color: #10131a;
    font-weight: 600;
  }
  .btn--primary:hover { filter: brightness(1.08); }

  .pager { display: flex; gap: 12px; align-items: center; margin-top: 20px; font-size: 13px; }
</style>
</head>
<body>

<header class="bar">
  <h1 class="bar__title"><a href="{{ route('admin.books.index') }}">ebook 管理画面</a></h1>

  {{-- ログイン画面ではヘッダーの操作を出さない --}}
  @auth
    <div class="bar__actions">
      <a href="{{ route('admin.books.create') }}" class="btn btn--primary">＋ 新規登録</a>
      <span class="bar__user">{{ auth()->user()->name }}</span>
      {{-- ログアウトは状態を変える操作なので GET ではなく POST (CSRF 付き) --}}
      <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit" class="btn">ログアウト</button>
      </form>
    </div>
  @endauth
</header>

<main class="wrap">
  @if (session('status'))
    <p class="flash">{{ session('status') }}</p>
  @endif

  @if ($errors->any())
    <ul class="errors">
      @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  @endif

  @yield('content')
</main>

</body>
</html>
