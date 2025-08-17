<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PostActionController extends Controller
{
    public function like(Post $post)
    {
        $user = Auth::user();

        // 既にいいねしているかチェック
        $existing = PostAction::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'like'
        ])->first();

        if ($existing) {
            // 既にアクション済みの場合は現在の状態を返す
            return response()->json([
                'message' => '既にいいね済みです。',
                'is_liked' => true,
                'likes_count' => $post->likes()->count()
            ]);
        }

        PostAction::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'like',
        ]);

        return response()->json([
            'message' => 'いいねしました。',
            'is_liked' => true,
            'likes_count' => $post->likes()->count()
        ]);
    }

    public function unlike(Post $post)
    {
        $user = Auth::user();

        $deleted = PostAction::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'like'
        ])->delete();

        return response()->json([
            'message' => $deleted ? 'いいねを取り消しました。' : '既にいいねを取り消し済みです。',
            'is_liked' => false,
            'likes_count' => $post->likes()->count()
        ]);
    }

    public function spark(Post $post)
    {
        $user = Auth::user();

        // 既にスパークしているかチェック
        $existing = PostAction::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'spark'
        ])->first();

        if ($existing) {
            // 既にアクション済みの場合は現在の状態を返す
            return response()->json([
                'message' => '既にスパーク済みです。',
                'is_sparked' => true,
                'sparks_count' => $post->sparks()->count()
            ]);
        }

        // スパークアクションを記録
        PostAction::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'spark',
        ]);

        return response()->json([
            'message' => 'スパークしました。',
            'is_sparked' => true,
            'sparks_count' => $post->sparks()->count()
        ]);
    }

    public function unspark(Post $post)
    {
        $user = Auth::user();

        // スパークアクションを削除
        $deleted = PostAction::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'spark'
        ])->delete();

        return response()->json([
            'message' => $deleted ? 'スパークを取り消しました。' : '既にスパークを取り消し済みです。',
            'is_sparked' => false,
            'sparks_count' => $post->sparks()->count()
        ]);
    }

    public function bookmark(Post $post)
    {
        $user = Auth::user();

        // 既にブックマークしているかチェック
        $existing = PostAction::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'bookmark'
        ])->first();

        if ($existing) {
            // 既にアクション済みの場合は現在の状態を返す
            return response()->json([
                'message' => '既にブックマーク済みです。',
                'is_bookmarked' => true,
                'bookmarks_count' => $post->bookmarks()->count()
            ]);
        }

        PostAction::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'bookmark',
        ]);

        return response()->json([
            'message' => 'ブックマークしました。',
            'is_bookmarked' => true,
            'bookmarks_count' => $post->bookmarks()->count()
        ]);
    }

    public function unbookmark(Post $post)
    {
        $user = Auth::user();

        $deleted = PostAction::where([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'action_type' => 'bookmark'
        ])->delete();

        return response()->json([
            'message' => $deleted ? 'ブックマークを取り消しました。' : '既にブックマークを取り消し済みです。',
            'is_bookmarked' => false,
            'bookmarks_count' => $post->bookmarks()->count()
        ]);
    }
}
