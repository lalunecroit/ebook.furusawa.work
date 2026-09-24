@extends('admin.layout')

@section('title', $book->title . ' のページ画像')

@section('content')
  <h2>ページ画像 <span style="color:var(--muted);font-size:13px">／ {{ $book->title }}</span></h2>

  <p style="color:var(--muted);font-size:13px;margin-top:-10px">
    <code>{{ $book->code }}</code> ・
    <span id="pagesCount">{{ $book->pages_count }}</span> ページ ・
    <a href="{{ route('admin.books.edit', $book) }}">書誌情報の編集へ</a>
  </p>

  {{-- 末尾に追加するための置き場。ここに落とすと次のページ番号で登録される --}}
  <div class="drop drop--add" id="addDrop" tabindex="0" role="button"
       aria-label="画像をドロップして末尾に追加">
    <p class="drop__title">ここに画像をドロップして追加</p>
    <p class="drop__hint">クリックしてファイルを選ぶこともできます（1 枚ずつ／jpg・png・webp・svg／8MB まで）</p>
  </div>

  <p class="upload-status" id="status" hidden></p>

  {{-- 既存ページ。各カードが差し替え用のドロップ先を兼ねる --}}
  <ul class="pagegrid" id="grid">
    @foreach ($book->pages as $page)
      <li class="pagecard drop" data-page-no="{{ $page->page_no }}" data-page-id="{{ $page->id }}"
          tabindex="0" role="button" aria-label="{{ $page->page_no }} ページ目を差し替え">
        <img class="pagecard__img" src="{{ $page->url }}" alt="">
        <div class="pagecard__foot">
          <span class="num">{{ $page->page_no }}</span>
          <button type="button" class="pagecard__del" data-page-id="{{ $page->id }}" title="このページを削除">削除</button>
        </div>
      </li>
    @endforeach
  </ul>

  <style>
    /* ------------------------------------------ ドロップ領域 */
    .drop {
      border: 1px dashed #545b64;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.02);
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
    }
    .drop.is-over { border-color: var(--accent); background: rgba(110, 160, 255, 0.10); }
    .drop:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .drop--add { padding: 26px 20px; text-align: center; margin-bottom: 18px; }
    .drop__title { margin: 0 0 4px; font-size: 14px; }
    .drop__hint { margin: 0; font-size: 12px; color: var(--muted); }

    /* ------------------------------------------ 状態表示 */
    .upload-status {
      margin: 0 0 18px;
      padding: 9px 14px;
      border-radius: 4px;
      border: 1px solid var(--border);
      font-size: 13px;
    }
    .upload-status--error { border-left: 3px solid var(--danger); color: #f0c9c9; }
    .upload-status--ok { border-left: 3px solid var(--ok); }

    /* ------------------------------------------ ページ一覧 */
    .pagegrid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 14px;
      list-style: none;
      margin: 0;
      padding: 0;
    }
    .pagecard { overflow: hidden; }
    .pagecard__img {
      display: block;
      width: 100%;
      aspect-ratio: 800 / 1131;
      object-fit: cover;
      background: #1f2226;
    }
    .pagecard__foot {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 6px 8px;
      font-size: 12px;
      color: var(--muted);
    }
    .pagecard__del {
      border: 0;
      background: none;
      color: var(--muted);
      font: inherit;
      font-size: 12px;
      cursor: pointer;
      padding: 0;
    }
    .pagecard__del:hover { color: var(--danger); }
    .pagecard.is-busy { opacity: 0.5; pointer-events: none; }
  </style>

  <script>
    (function () {
      'use strict';

      const uploadUrl = @json(route('admin.books.pages.store', $book));
      const deleteUrlBase = @json(url('admin/books/' . $book->code . '/pages'));
      const token = @json(csrf_token());

      const el = {
        addDrop: document.getElementById('addDrop'),
        grid: document.getElementById('grid'),
        status: document.getElementById('status'),
        count: document.getElementById('pagesCount'),
      };

      /* ---------------- 表示 ---------------- */

      function showStatus(message, kind) {
        el.status.textContent = message;
        el.status.className = `upload-status upload-status--${kind}`;
        el.status.hidden = false;
      }

      /* ---------------- 送信 ---------------- */

      // 1枚ずつ送る。pageNo を渡すと差し替え、省略すると末尾に追加
      async function upload(file, pageNo) {
        const body = new FormData();
        body.append('image', file);
        if (pageNo) body.append('page_no', pageNo);

        showStatus(`${file.name} を送信中…`, 'ok');

        const res = await fetch(uploadUrl, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
          body,
        });

        if (!res.ok) {
          // バリデーションエラーは 422 で { errors: { image: [...] } } が返る
          const data = await res.json().catch(() => ({}));
          const message = Object.values(data.errors ?? {}).flat()[0] ?? `アップロードに失敗しました (${res.status})`;
          throw new Error(message);
        }

        return res.json();
      }

      async function handleFiles(files, pageNo) {
        // 「1枚ずつ」の指定なので、複数落とされても先頭だけを扱う
        const file = files[0];
        if (!file) return;
        if (files.length > 1) {
          showStatus('1 枚ずつアップロードしてください。最初の 1 枚だけ受け付けます。', 'error');
        }

        try {
          const { page, pages_count: count } = await upload(file, pageNo);
          el.count.textContent = count;
          showStatus(`${page.page_no} ページ目を保存しました。`, 'ok');
          // 差し替えでも URL の ?v= が変わるので、再読み込みで新しい画像が出る
          location.reload();
        } catch (e) {
          showStatus(e.message, 'error');
        }
      }

      /* ---------------- ドラッグ&ドロップ ---------------- */

      function bindDrop(node, pageNo) {
        // dragover を止めないとブラウザが画像を開いてしまう
        node.addEventListener('dragover', (ev) => {
          ev.preventDefault();
          node.classList.add('is-over');
        });
        node.addEventListener('dragleave', () => node.classList.remove('is-over'));
        node.addEventListener('drop', (ev) => {
          ev.preventDefault();
          node.classList.remove('is-over');
          node.classList.add('is-busy');
          handleFiles(ev.dataTransfer.files, pageNo);
        });

        // ドラッグできない環境向けに、クリックでもファイルを選べるようにする
        node.addEventListener('click', (ev) => {
          if (ev.target.closest('.pagecard__del')) return;   // 削除ボタンは別扱い
          const input = document.createElement('input');
          input.type = 'file';
          input.accept = 'image/jpeg,image/png,image/webp,image/svg+xml';
          input.addEventListener('change', () => handleFiles(input.files, pageNo));
          input.click();
        });
      }

      bindDrop(el.addDrop, null);
      el.grid.querySelectorAll('.pagecard').forEach((card) => {
        bindDrop(card, card.dataset.pageNo);
      });

      /* ---------------- 削除 ---------------- */

      el.grid.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('.pagecard__del');
        if (!btn) return;
        ev.stopPropagation();

        if (!confirm('このページを削除します。よろしいですか？')) return;

        const res = await fetch(`${deleteUrlBase}/${btn.dataset.pageId}`, {
          method: 'DELETE',
          headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        });

        if (res.ok) {
          location.reload();
        } else {
          showStatus(`削除に失敗しました (${res.status})`, 'error');
        }
      });
    })();
  </script>
@endsection
