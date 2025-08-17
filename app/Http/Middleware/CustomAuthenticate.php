<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class CustomAuthenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // APIリクエストの場合はリダイレクトしない（nullを返す）
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // 通常のWebリクエストの場合はログインページへリダイレクト
        return route('login');
    }

    /**
     * Handle an unauthenticated user.
     */
    protected function unauthenticated($request, array $guards)
    {
        // APIリクエストの場合はJSONレスポンスを返す
        if ($request->expectsJson() || $request->is('api/*')) {
            abort(response()->json(['message' => 'Unauthenticated.'], 401));
        }

        // 通常のWebリクエストの場合は親クラスの処理を呼び出す
        parent::unauthenticated($request, $guards);
    }
}
