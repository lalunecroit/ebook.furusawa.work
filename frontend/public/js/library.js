/* ==========================================================================
 * 書籍一覧 (index.html)
 *
 *   ブラウザ ──▶ API (:8000) /api/books ──▶ 書誌情報と表紙URL (JSON)
 *          └──▶ CDN (:8082) ──▶ 表紙画像
 *
 * ビューア (reader.js) と同じ方針で、状態は state ひとつに集め、
 * 描画は render() が state から一方向に作る。
 * ========================================================================== */

'use strict';

const CONFIG = {
  /** バックエンド API。reader.js と同じ値 */
  api: {
    base: 'http://localhost:8000/api',
  },

  /** 1ページあたりの表示件数。API 側の上限は 100 */
  perPage: 12,

  /** 読む画面。カードのリンク先 */
  readerPath: 'reader.html',
};

const state = {
  page: 1,
  lastPage: 1,
  total: 0,
  books: [],
};

const el = {
  count: document.getElementById('count'),
  grid: document.getElementById('grid'),
  state: document.getElementById('state'),
  pager: document.getElementById('pager'),
  prev: document.getElementById('prev'),
  next: document.getElementById('next'),
  pagerStatus: document.getElementById('pagerStatus'),
  liveRegion: document.getElementById('liveRegion'),
};


/* ==========================================================================
 * 取得
 * ========================================================================== */

async function fetchBooks(page) {
  const url = `${CONFIG.api.base}/books?per_page=${CONFIG.perPage}&page=${page}`;
  const res = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`API ${res.status} ${res.statusText}`);

  const json = await res.json();

  state.books = json.data;
  state.page = json.meta.current_page;
  state.lastPage = json.meta.last_page;
  state.total = json.meta.total;
}


/* ==========================================================================
 * 描画
 * ========================================================================== */

/** 2026-09-20T00:00:00+09:00 → 2026.09.20 */
function formatDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}.${pad(d.getMonth() + 1)}.${pad(d.getDate())}`;
}

function buildCard(book) {
  const li = document.createElement('li');

  const a = document.createElement('a');
  a.className = 'card';
  a.href = `${CONFIG.readerPath}?book=${encodeURIComponent(book.code)}`;

  // 表紙。API が cover を返さない本 (ページ未登録) でも崩れないようにする
  if (book.cover?.url) {
    const img = document.createElement('img');
    img.className = 'card__cover';
    img.src = book.cover.url;
    img.alt = '';          // 書名は下のテキストにあるので画像は装飾扱い
    img.loading = 'lazy';
    img.decoding = 'async';
    a.append(img);
  }

  const body = document.createElement('div');
  body.className = 'card__body';

  const title = document.createElement('h2');
  title.className = 'card__title';
  title.textContent = book.title;

  const desc = document.createElement('p');
  desc.className = 'card__desc';
  desc.textContent = book.description ?? '';

  const meta = document.createElement('p');
  meta.className = 'card__meta';
  const date = document.createElement('span');
  date.textContent = formatDate(book.published_at);
  const pages = document.createElement('span');
  pages.textContent = `${book.total_pages} ページ`;
  meta.append(date, pages);

  body.append(title, desc, meta);
  a.append(body);
  li.append(a);

  return li;
}

function render() {
  el.grid.replaceChildren(...state.books.map(buildCard));

  el.count.textContent = state.total > 0 ? `全 ${state.total} 冊` : '';

  // 1ページに収まるならページ送りは出さない
  el.pager.hidden = state.lastPage <= 1;
  el.prev.disabled = state.page <= 1;
  el.next.disabled = state.page >= state.lastPage;
  el.pagerStatus.textContent = `${state.page} / ${state.lastPage}`;

  if (state.books.length === 0) {
    showState('公開されている書籍がありません。');
  } else {
    hideState();
    el.liveRegion.textContent = `${state.total} 冊中 ${state.books.length} 冊を表示しています`;
  }
}

function showState(message, isError = false) {
  el.state.textContent = message;
  el.state.classList.toggle('state--error', isError);
  el.state.hidden = false;
  if (isError) console.error('[library]', message);
}

function hideState() {
  el.state.hidden = true;
}


/* ==========================================================================
 * ページ送り
 * ========================================================================== */

async function goToPage(page) {
  if (page < 1 || page > state.lastPage) return;

  showState('読み込み中…');
  try {
    await fetchBooks(page);
    render();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (e) {
    el.grid.replaceChildren();
    showState(`書籍一覧を取得できませんでした: ${e.message}`, true);
  }
}

function bindEvents() {
  el.prev.addEventListener('click', () => goToPage(state.page - 1));
  el.next.addEventListener('click', () => goToPage(state.page + 1));
}


/* ==========================================================================
 * 起動
 * ========================================================================== */

(async function init() {
  bindEvents();
  await goToPage(1);
})();
