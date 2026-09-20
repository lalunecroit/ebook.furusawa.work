#!/usr/bin/env python3
"""ビューア動作確認用のダミーページ画像(SVG)を生成する。

本番では実際の書籍画像に差し替える。ファイル名の規則
(page-01.svg ... page-NN.svg) は js/reader.js の CONFIG.pageSrc と対応。

使い方:
    python3 tools/generate_dummy_pages.py
"""

from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
OUT_DIR = ROOT_DIR / "frontend" / "public" / "assets" / "pages"

# ページサイズ。A5 相当の縦横比 (1:1.414)
W, H = 800, 1131

FONT = "'Hiragino Sans','Noto Sans JP',sans-serif"

# ページごとのアクセントカラー。めくったことが目で分かるよう少しずつ変える
ACCENTS = [
    "#1f3a5f", "#2f6feb", "#0f8a7a", "#b5622a", "#7a3f9d",
    "#c0392b", "#16794c", "#8a6d1f", "#3b5bdb", "#1f3a5f",
]


def esc(s: str) -> str:
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def text(x, y, s, size=24, color="#1b1f24", weight="400", anchor="start"):
    return (
        f'<text x="{x}" y="{y}" font-family="{FONT}" font-size="{size}" '
        f'font-weight="{weight}" fill="{color}" text-anchor="{anchor}">{esc(s)}</text>'
    )


def body_lines(top, count, accent, indent=0):
    """本文っぽいグレーの帯を描いて「文章が組まれている」感を出す"""
    out = []
    y = top
    for i in range(count):
        # 行長をばらつかせて自然に見せる
        width = [640, 600, 655, 580, 630, 610, 660, 545][i % 8] - indent
        out.append(
            f'<rect x="{80 + indent}" y="{y}" width="{width}" height="10" '
            f'rx="5" fill="#1b1f24" opacity="0.14"/>'
        )
        y += 30
    return "".join(out), y


def frame(page_no, accent, inner):
    """全ページ共通の枠・ノンブル(ページ番号)"""
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">
  <rect width="{W}" height="{H}" fill="#ffffff"/>
  <rect x="0" y="0" width="{W}" height="12" fill="{accent}"/>
  {inner}
  <line x1="80" y1="{H-90}" x2="{W-80}" y2="{H-90}" stroke="{accent}" stroke-opacity="0.3" stroke-width="1"/>
  {text(W//2, H-55, f"- {page_no} -", size=20, color="#8b95a1", anchor="middle")}
</svg>
'''


def cover(page_no, accent):
    inner = f'''
  <rect width="{W}" height="{H}" fill="{accent}"/>
  <rect x="70" y="70" width="{W-140}" height="{H-140}" fill="none" stroke="#ffffff" stroke-opacity="0.45" stroke-width="2"/>
  {text(W//2, 430, "Docker で作る", size=40, color="#ffffff", weight="300", anchor="middle")}
  {text(W//2, 510, "電子書籍サービス", size=56, color="#ffffff", weight="700", anchor="middle")}
  <line x1="260" y1="560" x2="{W-260}" y2="560" stroke="#ffffff" stroke-opacity="0.6" stroke-width="2"/>
  {text(W//2, 620, "— フロントエンド編 —", size=24, color="#ffffff", anchor="middle")}
  {text(W//2, H-140, "furusawa.work", size=22, color="#ffffff", anchor="middle")}
'''
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">{inner}</svg>
'''


def toc(page_no, accent):
    items = [
        ("第1章", "ビューアの構造", "03"),
        ("第2章", "状態管理の考え方", "04"),
        ("第3章", "ページめくりの実装", "05"),
        ("第4章", "入力ハンドリング", "06"),
        ("第5章", "画像のプリロード", "07"),
        ("第6章", "バックエンド連携", "08"),
    ]
    out = [text(80, 160, "目 次", size=42, weight="700", color=accent)]
    out.append(f'<line x1="80" y1="190" x2="{W-80}" y2="190" stroke="{accent}" stroke-width="3"/>')
    y = 270
    for ch, title, pg in items:
        out.append(text(80, y, ch, size=20, color="#8b95a1"))
        out.append(text(180, y, title, size=26))
        out.append(text(W - 80, y, pg, size=22, color="#8b95a1", anchor="end"))
        out.append(f'<line x1="180" y1="{y+12}" x2="{W-110}" y2="{y+12}" stroke="#1b1f24" stroke-opacity="0.12" stroke-dasharray="2 4"/>')
        y += 80
    return frame(page_no, accent, "".join(out))


def chapter(page_no, accent, num, title):
    out = [
        text(80, 150, f"CHAPTER {num}", size=18, color=accent, weight="700"),
        text(80, 210, title, size=34, weight="700"),
        f'<line x1="80" y1="240" x2="240" y2="240" stroke="{accent}" stroke-width="4"/>',
    ]
    lines, y = body_lines(300, 9, accent)
    out.append(lines)
    # 引用ブロック
    out.append(f'<rect x="80" y="{y+30}" width="4" height="110" fill="{accent}"/>')
    out.append(text(110, y + 70, "状態を1か所に集めておくと、", size=22, color="#5b6672"))
    out.append(text(110, y + 105, "描画はその写像として書ける。", size=22, color="#5b6672"))
    lines2, _ = body_lines(y + 190, 8, accent)
    out.append(lines2)
    return frame(page_no, accent, "".join(out))


def figure(page_no, accent, num, title):
    out = [
        text(80, 150, f"CHAPTER {num}", size=18, color=accent, weight="700"),
        text(80, 210, title, size=34, weight="700"),
        f'<line x1="80" y1="240" x2="240" y2="240" stroke="{accent}" stroke-width="4"/>',
    ]
    lines, y = body_lines(300, 5, accent)
    out.append(lines)
    # 図版: state -> render -> DOM
    fy = y + 40
    boxes = [("state", 80), ("render()", 320), ("DOM", 560)]
    for label, bx in boxes:
        out.append(f'<rect x="{bx}" y="{fy}" width="160" height="80" rx="8" fill="none" stroke="{accent}" stroke-width="2"/>')
        out.append(text(bx + 80, fy + 48, label, size=22, color=accent, anchor="middle"))
    for bx in (240, 480):
        out.append(f'<line x1="{bx}" y1="{fy+40}" x2="{bx+80}" y2="{fy+40}" stroke="{accent}" stroke-width="2"/>')
        out.append(f'<path d="M{bx+80} {fy+40} l-10 -5 v10 z" fill="{accent}"/>')
    out.append(text(W // 2, fy + 130, f"図 {num}-1  単方向データフロー", size=18, color="#8b95a1", anchor="middle"))
    lines2, _ = body_lines(fy + 175, 7, accent)
    out.append(lines2)
    return frame(page_no, accent, "".join(out))


def afterword(page_no, accent):
    out = [
        text(80, 170, "あとがき", size=34, weight="700"),
        f'<line x1="80" y1="200" x2="240" y2="200" stroke="{accent}" stroke-width="4"/>',
    ]
    lines, _ = body_lines(270, 14, accent)
    out.append(lines)
    return frame(page_no, accent, "".join(out))


def colophon(page_no, accent):
    rows = [
        ("書名", "Docker で作る電子書籍サービス"),
        ("発行", "2026年9月20日 初版"),
        ("著者", "Yuuki Furusawa"),
        ("発行所", "furusawa.work"),
    ]
    out = [
        text(W // 2, 300, "奥 付", size=30, weight="700", anchor="middle", color=accent),
        f'<line x1="280" y1="330" x2="{W-280}" y2="330" stroke="{accent}" stroke-width="2"/>',
    ]
    y = 410
    for k, v in rows:
        out.append(text(200, y, k, size=20, color="#8b95a1"))
        out.append(text(320, y, v, size=20))
        y += 50
    out.append(text(W // 2, H - 200, "© 2026 furusawa.work", size=18, color="#8b95a1", anchor="middle"))
    return frame(page_no, accent, "".join(out))


PAGES = [
    cover,
    toc,
    lambda n, a: chapter(n, a, 1, "ビューアの構造"),
    lambda n, a: figure(n, a, 2, "状態管理の考え方"),
    lambda n, a: chapter(n, a, 3, "ページめくりの実装"),
    lambda n, a: chapter(n, a, 4, "入力ハンドリング"),
    lambda n, a: figure(n, a, 5, "画像のプリロード"),
    lambda n, a: chapter(n, a, 6, "バックエンド連携"),
    afterword,
    colophon,
]


def main():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for i, build in enumerate(PAGES, start=1):
        path = OUT_DIR / f"page-{i:02d}.svg"
        path.write_text(build(i, ACCENTS[i - 1]), encoding="utf-8")
        print(f"wrote {path.relative_to(ROOT_DIR)}")
    print(f"\n{len(PAGES)} pages generated.")


if __name__ == "__main__":
    main()
