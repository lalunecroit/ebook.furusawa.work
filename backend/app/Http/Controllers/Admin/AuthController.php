<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 管理画面のログイン / ログアウト。
 *
 * 利用者の登録画面は用意しない (管理者は artisan admin:user で作る)。
 */
class AuthController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // ログイン前のセッション ID を引き継がない (セッション固定攻撃の対策)
        $request->session()->regenerate();

        // ログイン画面に飛ばされる前に開こうとしていたページへ戻す
        return redirect()->intended(route('admin.books.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        // セッションを破棄し、CSRF トークンも作り直す
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
