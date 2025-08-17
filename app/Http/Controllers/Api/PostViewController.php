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
            PostView::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'viewed_at' => now(),
            ]);
        }

        return response()->json(['message' => '閲覧履歴を記録しました。']);
    }
}
