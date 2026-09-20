/* ==========================================================================
 * 電子書籍ビューア (フロントエンド)
 *
 * 設計方針
 *   1. 状態は state オブジェクト1つに集約する
 *   2. 画面の見た目は render() が state から一方向に作る
 *   3. 入力(ボタン/キー/スワイプ)は state を変えて render() を呼ぶだけ
 *
 *      入力 ──▶ state を更新 ──▶ render() ──▶ DOM
 *
 *   この形にしておくと「どこで画面が変わるのか」が render() 一箇所に
 *   集まるので、機能追加のときに追いかける場所が減る。
 *
 * 目次
 *   1. 設定 (CONFIG) と書誌データの取得 (API)
 *   2. 状態 (state)
 *   3. DOM 参照
 *   4. 小さなヘルパー
 *   5. 描画 render()
 *   6. ページ遷移 goTo() ← めくりアニメーションの本体
 *   7. 画像のプリロード
 *   8. しおり (localStorage)
 *   9. 入力ハンドリング
 *  10. 起動
 * ========================================================================== */

'use strict';

/* ==========================================================================
 * 1. 設定と書誌データの取得
 * --------------------------------------------------------------------------
 * 総ページ数とページ画像URLはバックエンド API から取る。
 * 画像そのものは API が返した URL (CDN) をブラウザが直接読む。
 *
 *   ブラウザ ──▶ API (:8000) ──▶ ページ一覧(JSON)
 *          └──▶ CDN (:8082) ──▶ 画像
 * ========================================================================== */
const CONFIG = {
  /** バックエンド API。ホストが変わるのはここだけ */
  api: {
    base: 'http://localhost:8000/api',
    slug: 'sample',
  },

  /** 総ページ数。起動時に API の total_pages で上書きする */
  totalPages: 0,

  /**
   * ページ番号 → 画像URL の対応。
   * 中身は API のレスポンス (pageUrls) なので、画像の置き場所が
   * 変わってもフロント側は変更不要。
   */
  pageSrc: (n) => pageUrls.get(n) ?? '',

  /** 綴じ方向 'ltr' = 左綴じ(横書き) / 'rtl' = 右綴じ(縦書き・マンガ) */
  direction: 'ltr',

  /** めくりアニメーションの時間(ms)。CSS の --flip-duration と必ず揃える */
  animationMs: 600,

  /** 現在ページの前後何ページ分を先読みするか */
  preloadRange: 2,

  /** スワイプと判定する最小の横移動量(px) */
  swipeThreshold: 50,

  /** しおりの保存キー。本ごとに変える想定 */
  storageKey: 'ebook:last-page:sample-001',
};

/** ページ番号 → 画像URL。API のレスポンスで埋める */
const pageUrls = new Map();

/**
 * 書誌情報とページ一覧を API から取得して pageUrls / CONFIG.totalPages を埋める。
 * 失敗したら例外を投げ、init() 側でエラー表示に回す。
 */
async function fetchBook() {
  const url = `${CONFIG.api.base}/books/${CONFIG.api.slug}`;
  const res = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`API ${res.status} ${res.statusText}`);

  const { data } = await res.json();

  pageUrls.clear();
  for (const page of data.pages) {
    pageUrls.set(page.page_no, page.url);
  }
  CONFIG.totalPages = data.total_pages ?? pageUrls.size;

  return data;
}


/* ==========================================================================
 * 2. 状態
 * --------------------------------------------------------------------------
 * 「今の画面を復元するのに必要な最小限の値」だけを置く。
 * ここに無い情報は render() の中で都度求める(派生値は持たない)。
 * ========================================================================== */
const state = {
  /** 現在のページ番号 (1 始まり) */
  current: 1,

  /** 綴じ方向。実行中に切り替えられるので CONFIG とは別に持つ */
  direction: CONFIG.direction,

  /** めくり中フラグ。true の間は次の操作を受け付けない(二重発火防止) */
  isFlipping: false,
};


/* ==========================================================================
 * 3. DOM 参照
 * --------------------------------------------------------------------------
 * querySelector は起動時に1回だけ。以後は el.xxx を使う。
 * ========================================================================== */
const el = {
  reader:      document.getElementById('reader'),
  bookTitle:   document.getElementById('bookTitle'),
  stage:       document.getElementById('stage'),
  book:        document.getElementById('book'),
  pageUnder:   document.getElementById('pageUnder'),  // 背面(下地)
  sheet:       document.getElementById('sheet'),      // 回転する紙
  pageFlip:    document.getElementById('pageFlip'),   // 紙の表面の画像
  spinner:     document.getElementById('spinner'),
  seek:        document.getElementById('seek'),
  pagerCurrent:document.getElementById('pagerCurrent'),
  pagerTotal:  document.getElementById('pagerTotal'),
  directionLabel: document.getElementById('directionLabel'),
  liveRegion:  document.getElementById('liveRegion'),
  navLeft:     document.querySelector('[data-nav="left"]'),
  navRight:    document.querySelector('[data-nav="right"]'),
};


/* ==========================================================================
 * 4. 小さなヘルパー
 * ========================================================================== */

/** ページ番号を 1..totalPages の範囲に収める */
function clampPage(n) {
  return Math.min(Math.max(Math.round(n), 1), CONFIG.totalPages);
}

/**
 * 綴じ目(背表紙)の位置 = 紙を回転させる軸。
 * 左綴じなら左端、右綴じなら右端が軸になる。
 */
function spineOrigin() {
  return state.direction === 'rtl' ? 'right center' : 'left center';
}

/**
 * めくり切ったときの回転角。
 * 左綴じは左へ倒れるのでマイナス、右綴じは右へ倒れるのでプラス。
 */
function flipAngle() {
  return state.direction === 'rtl' ? 180 : -180;
}

/**
 * 画面の「左側/右側」を押したときに進むページ数。
 *   左綴じ: 右を押す → 次ページ (+1)
 *   右綴じ: 左を押す → 次ページ (+1)   ※マンガと同じ
 */
function deltaForSide(side) {
  const forwardSide = state.direction === 'rtl' ? 'left' : 'right';
  return side === forwardSide ? 1 : -1;
}

/** transition を挟まずに紙の角度を設定する */
function setSheetAngle(deg) {
  el.sheet.style.transform = `rotateY(${deg}deg)`;
}

/**
 * スタイル変更をブラウザに「今すぐ」反映させる。
 * transition 無しで開始位置に置く → 反映 → transition 有りで終了位置へ、
 * という手順を踏まないとアニメーションが再生されずに飛んでしまう。
 * offsetWidth の読み取りが強制的なレイアウト計算(リフロー)を起こす。
 */
function forceReflow(node) {
  void node.offsetWidth;
}


/* ==========================================================================
 * 5. 描画
 * --------------------------------------------------------------------------
 * state を見て UI を合わせるだけ。DOM を書き換える処理は
 * (ページ画像の差し替えを除き) すべてここに集約する。
 * ========================================================================== */
function render() {
  const { current, direction } = state;

  // ページ番号表示とシークバー
  el.pagerCurrent.textContent = String(current);
  el.pagerTotal.textContent = String(CONFIG.totalPages);
  el.seek.max = String(CONFIG.totalPages);
  el.seek.value = String(current);

  // 綴じ方向 (CSS 側は [data-direction] で影の向きを変えている)
  el.reader.dataset.direction = direction;
  el.directionLabel.textContent = direction === 'rtl' ? '右綴じ' : '左綴じ';
  el.sheet.style.transformOrigin = spineOrigin();

  // 端に来たらナビを無効化
  el.navLeft.disabled  = !isReachable(current + deltaForSide('left'));
  el.navRight.disabled = !isReachable(current + deltaForSide('right'));

  // スクリーンリーダーへの通知
  el.liveRegion.textContent = `${CONFIG.totalPages} ページ中 ${current} ページ目`;
  el.pageFlip.alt = `${current} ページ`;
}

/** そのページ番号が実在するか */
function isReachable(n) {
  return n >= 1 && n <= CONFIG.totalPages;
}


/* ==========================================================================
 * 6. ページ遷移 (めくりアニメーション)
 * --------------------------------------------------------------------------
 * レイヤ構成 (奥 → 手前)
 *
 *     #pageUnder  … 下地。常に表示されている
 *     #sheet      … 回転する紙。#pageFlip(画像) + 影を載せている
 *
 * 静止しているときは両方とも「現在ページ」を表示している。
 * 同じ絵が重なっているので、回転を 0deg に戻す瞬間にもチラつかない。
 *
 * ▼ 進む (forward)
 *     下地に「遷移先」を敷く → 紙(現在ページ)を綴じ目から倒す
 *     → 90度を超えると backface-visibility:hidden で紙が消え、下地が現れる
 *
 * ▼ 戻る (backward)
 *     下地は「現在ページ」のまま → 紙に「遷移先」を貼り、倒れた状態(±180deg)
 *     から起こす → 90度を切ったところで紙が現れて手前に被さる
 *
 * つまり進む/戻るは「紙に貼る絵」と「回転の向き」が逆なだけで同じ仕組み。
 * ========================================================================== */
function goTo(target, options = {}) {
  const animate = options.animate !== false;
  const next = clampPage(target);

  // めくり中 / 同じページなら何もしない
  if (state.isFlipping || next === state.current) return;

  // --- アニメーション無しで切り替える場合 (初期表示・シークバーのドラッグ中) ---
  if (!animate || CONFIG.animationMs <= 0) {
    state.current = next;
    el.pageUnder.src = CONFIG.pageSrc(next);
    el.pageFlip.src = CONFIG.pageSrc(next);
    afterPageChange();
    return;
  }

  const forward = next > state.current;
  state.isFlipping = true;

  // --- ① 下地と紙に表示する絵を決める ---
  if (forward) {
    // 下地 = 遷移先 / 紙 = 現在ページ(すでに表示済みなので触らない)
    el.pageUnder.src = CONFIG.pageSrc(next);
  } else {
    // 下地 = 現在ページ(すでにそうなっている) / 紙 = 遷移先
    el.pageFlip.src = CONFIG.pageSrc(next);
  }
  showSpinnerUntilLoaded(next);

  // --- ② transition を切った状態で開始角度に置く ---
  const angle = flipAngle();
  el.sheet.classList.remove('is-animating');
  el.sheet.style.transformOrigin = spineOrigin();
  setSheetAngle(forward ? 0 : angle);
  el.sheet.classList.toggle('is-shaded', !forward); // 戻るときは影ありから始める

  forceReflow(el.sheet);   // ここまでを確定させる

  // --- ③ transition を入れて終了角度へ。あとは CSS が補間してくれる ---
  el.sheet.classList.add('is-animating');
  setSheetAngle(forward ? angle : 0);
  el.sheet.classList.toggle('is-shaded', forward);  // 進むときは影を濃くしていく

  // --- ④ 後始末を予約 ---
  // transitionend は「タブが裏に回った」等で発火しないことがあるため、
  // setTimeout を正としてタイマーで確実に終わらせる。
  window.clearTimeout(goTo._timer);
  goTo._timer = window.setTimeout(() => finishFlip(next), CONFIG.animationMs);
}

/**
 * めくり終了時の後始末。
 * 紙を 0deg に戻し、下地・紙の両方を遷移先の絵に揃えて静止状態に戻す。
 */
function finishFlip(next) {
  state.current = next;

  el.sheet.classList.remove('is-animating', 'is-shaded');
  setSheetAngle(0);

  const src = CONFIG.pageSrc(next);
  el.pageFlip.src = src;
  el.pageUnder.src = src;

  state.isFlipping = false;
  afterPageChange();
}

/** ページが変わったあとに毎回やること */
function afterPageChange() {
  render();
  preloadAround(state.current);
  saveProgress(state.current);
}

/** 相対移動。delta が +1 なら次ページ、-1 なら前ページ */
function move(delta) {
  const target = state.current + delta;
  if (!isReachable(target)) return;
  goTo(target);
}


/* ==========================================================================
 * 7. 画像のプリロード
 * --------------------------------------------------------------------------
 * めくった瞬間に白紙が見えるのを防ぐため、前後のページを先に読ませておく。
 * new Image() に src を入れるだけでブラウザのキャッシュに載る。
 * ========================================================================== */
const imageCache = new Map();   // ページ番号 → HTMLImageElement

function preload(n) {
  if (!isReachable(n)) return null;
  if (imageCache.has(n)) return imageCache.get(n);

  const img = new Image();
  img.decoding = 'async';
  img.src = CONFIG.pageSrc(n);
  imageCache.set(n, img);
  return img;
}

/** 現在ページの前後 preloadRange ページを先読み */
function preloadAround(n) {
  for (let d = -CONFIG.preloadRange; d <= CONFIG.preloadRange; d++) {
    preload(n + d);
  }
}

/**
 * まだ読み込めていないページに飛ぶとき(シークバーで遠くへ移動した等)は
 * スピナーを出す。読み込み済みなら何も起きない。
 */
function showSpinnerUntilLoaded(n) {
  const img = preload(n);
  if (!img || img.complete) return;

  el.spinner.hidden = false;
  const hide = () => { el.spinner.hidden = true; };
  img.decode().then(hide, hide);
}


/* ==========================================================================
 * 8. しおり
 * --------------------------------------------------------------------------
 * localStorage はプライベートウィンドウや設定次第で例外を投げるので、
 * 読み書きは必ず try/catch で包み、失敗しても動作を止めない。
 * ※ 将来ログイン機能を入れたら、ここをバックエンド API に差し替える。
 * ========================================================================== */
function saveProgress(page) {
  try {
    localStorage.setItem(CONFIG.storageKey, String(page));
  } catch (e) {
    /* 保存できなくても閲覧は続けられるので握りつぶす */
  }
}

function loadProgress() {
  try {
    const saved = Number(localStorage.getItem(CONFIG.storageKey));
    return Number.isFinite(saved) && saved >= 1 ? clampPage(saved) : 1;
  } catch (e) {
    return 1;
  }
}


/* ==========================================================================
 * 9. 入力ハンドリング
 * ========================================================================== */
function bindEvents() {

  /* --- 画面左右のナビボタン --- */
  el.navLeft.addEventListener('click',  () => move(deltaForSide('left')));
  el.navRight.addEventListener('click', () => move(deltaForSide('right')));

  /* --- ツールバー --- */
  el.reader.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;

    if (btn.dataset.action === 'toggle-direction') {
      state.direction = state.direction === 'rtl' ? 'ltr' : 'rtl';
      render();
    }
    if (btn.dataset.action === 'toggle-fullscreen') {
      toggleFullscreen();
    }
  });

  /* --- キーボード --- */
  document.addEventListener('keydown', (ev) => {
    // 入力欄にフォーカスがあるときは邪魔しない
    if (ev.target.matches('input, textarea, select')) return;

    switch (ev.key) {
      case 'ArrowLeft':  move(deltaForSide('left'));  break;
      case 'ArrowRight': move(deltaForSide('right')); break;
      case 'PageDown':   move(1);  break;
      case 'PageUp':     move(-1); break;
      case ' ':          move(ev.shiftKey ? -1 : 1); break;
      case 'Home':       goTo(1); break;
      case 'End':        goTo(CONFIG.totalPages); break;
      case 'f': case 'F': toggleFullscreen(); break;
      default: return;   // 関係ないキーは既定動作を残す
    }
    ev.preventDefault();
  });

  /* --- スワイプ (Pointer Events でマウス/タッチ/ペンを一括で扱う) --- */
  let startX = 0;
  let startY = 0;
  let tracking = false;

  el.stage.addEventListener('pointerdown', (ev) => {
    if (ev.target.closest('.nav')) return;   // ボタンのクリックは邪魔しない
    tracking = true;
    startX = ev.clientX;
    startY = ev.clientY;
  });

  el.stage.addEventListener('pointerup', (ev) => {
    if (!tracking) return;
    tracking = false;

    const dx = ev.clientX - startX;
    const dy = ev.clientY - startY;

    // 横移動が threshold を超え、かつ縦移動より大きいときだけスワイプ扱い
    if (Math.abs(dx) < CONFIG.swipeThreshold) return;
    if (Math.abs(dx) < Math.abs(dy)) return;

    // 左へスワイプ(dx<0) = 画面右側へ進む操作
    move(deltaForSide(dx < 0 ? 'right' : 'left'));
  });

  el.stage.addEventListener('pointercancel', () => { tracking = false; });

  /* --- シークバー --- */
  // ドラッグ中(input)はアニメーション無しで即表示、離したら確定
  el.seek.addEventListener('input', () => {
    goTo(Number(el.seek.value), { animate: false });
  });

  /* --- 画像の読み込み失敗 --- */
  el.pageFlip.addEventListener('error', () => {
    el.spinner.hidden = true;
    console.error('[reader] ページ画像の読み込みに失敗しました:', el.pageFlip.src);
  });
}

function toggleFullscreen() {
  if (document.fullscreenElement) {
    document.exitFullscreen();
  } else {
    el.reader.requestFullscreen?.().catch(() => { /* 非対応環境は無視 */ });
  }
}


/* ==========================================================================
 * 10. 起動
 * ========================================================================== */
/** 起動に失敗したときの表示。ビューアは出せないので理由だけ残す */
function showFatal(message) {
  el.spinner.hidden = true;
  const box = document.createElement('p');
  box.className = 'fatal';
  box.textContent = message;
  el.stage.append(box);
  el.liveRegion.textContent = message;
  console.error('[reader]', message);
}

async function init() {
  // CSS の --flip-duration と JS の animationMs がずれていると
  // アニメーション途中で後始末が走るので、CSS 側を JS に合わせる
  document.documentElement.style.setProperty('--flip-duration', `${CONFIG.animationMs}ms`);

  // ページ画像は API から取る。これが無いと何も表示できないので先に待つ
  el.spinner.hidden = false;
  try {
    const book = await fetchBook();
    if (book.title) {
      el.bookTitle.textContent = book.title;
      document.title = book.title;
    }
  } catch (e) {
    showFatal(`書誌情報を取得できませんでした: ${e.message}`);
    return;
  }
  el.spinner.hidden = true;

  // しおりから復帰
  state.current = loadProgress();

  const src = CONFIG.pageSrc(state.current);
  el.pageUnder.src = src;
  el.pageFlip.src = src;

  showSpinnerUntilLoaded(state.current);
  bindEvents();
  render();
  preloadAround(state.current);
}

init();

/* デバッグ・将来の拡張用にコンソールから触れるようにしておく
   例) window.reader.goTo(5)                 5ページへ
       window.reader.state                   現在の状態を確認
       window.reader.CONFIG.animationMs = 0  めくりを即時に         */
window.reader = { CONFIG, state, goTo, move, render };
