<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 監視と疎通確認用のヘルスチェック。
 *
 * 既定は「アプリが起動してリクエストを返せるか」だけを見る浅い確認で、DB には触らない。
 * LB のヘルスチェックはこちらを使う。DB まで見て 503 を返す作りにすると、DB が
 * 一瞬詰まっただけで全インスタンスが不健全と判定されて同時に作り直され、
 * 復旧どころか障害が広がるため。
 *
 * DB まで確かめたいときは ?deep=1 を付ける。こちらは監視や手動の疎通確認向けで、
 * 接続できなければ 503 を返す。
 *
 * Laravel 組み込みの /up は HTML を返すので、機械で読む用にこちらを別に持つ。
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = [
            'status' => 'ok',
            // どのデプロイに当たっているかの確認用
            'env' => app()->environment(),
            'time' => now()->toIso8601String(),
        ];

        if (! $request->boolean('deep')) {
            return response()->json($payload);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            // 例外の本文には接続先やユーザ名が載りうるので、応答には出さずログへ回す
            report($e);

            return response()->json([
                ...$payload,
                'status' => 'error',
                'database' => 'error',
            ], 503);
        }

        return response()->json([...$payload, 'database' => 'ok']);
    }
}
