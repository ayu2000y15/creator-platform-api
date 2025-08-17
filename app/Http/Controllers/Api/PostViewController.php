<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PostViewController extends Controller
{
    public function store(Post $post)
    {
        $user = Auth::user();

        // 既に閲覧履歴があるかチェック
        $existing = PostView::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
        ])->first();

        if (!$existing) {
            // 閲覧履歴を記録
            PostView::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'viewed_at' => now(),
            ]);

            // 投稿のビュー数をインクリメント
            $post->increment('views_count');
        }

        return response()->json(['message' => '閲覧履歴を記録しました。']);
    }
}
