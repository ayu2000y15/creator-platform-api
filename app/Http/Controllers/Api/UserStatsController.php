<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostView;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserStatsController extends Controller
{
    /**
     * ユーザーの統計データを取得
     */
    public function getUserStats(Request $request): JsonResponse
    {
        $user = $request->user();

        // 投稿数を取得
        $postCount = Post::where('user_id', $user->id)->count();

        // 総ビュー数を取得（ユーザーの全投稿のビュー数の合計）
        $totalViews = Post::where('user_id', $user->id)->sum('views_count');

        // フォロワー数を取得（Userモデルのfollowersリレーションを使用）
        $userWithFollowers = $user->loadCount('followers');
        $followerCount = $userWithFollowers->followers_count;

        return response()->json([
            'post_count' => $postCount,
            'total_views' => $totalViews,
            'follower_count' => $followerCount,
        ]);
    }
}
