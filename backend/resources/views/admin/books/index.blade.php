@extends('admin.layout')

@section('title', '書籍一覧')

@section('content')
  <h2>書籍一覧 <span class="num" style="color:var(--muted);font-size:13px">（{{ $books->total() }} 件）</span></h2>

  <table>
    <thead>
      <tr>
        <th>書籍コード</th>
        <th>書名</th>
        <th>状態</th>
        <th class="num">ページ</th>
        <th>公開日時</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($books as $book)
        <tr>
          <td><code>{{ $book->code }}</code></td>
          <td>{{ $book->title }}</td>
          <td>
            {{-- published_at は null=未公開 / 未来=公開予約 / 過去=公開中 --}}
            @if ($book->published_at === null)
              <span class="badge">未公開</span>
            @elseif ($book->published_at->isFuture())
              <span class="badge">公開予約</span>
            @else
              <span class="badge badge--public">公開中</span>
            @endif
          </td>
          <td class="num">{{ $book->pages_count }}</td>
          <td class="num">{{ $book->published_at?->format('Y-m-d H:i') ?? '—' }}</td>
          <td><a href="{{ route('admin.books.edit', $book) }}">編集</a></td>
        </tr>
      @empty
        <tr><td colspan="6" style="color:var(--muted)">書籍がまだありません。</td></tr>
      @endforelse
    </tbody>
  </table>

  @if ($books->hasPages())
    <div class="pager">{{ $books->links() }}</div>
  @endif
@endsection
