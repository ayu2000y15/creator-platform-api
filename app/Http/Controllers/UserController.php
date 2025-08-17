<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * ユーザープロフィールを取得
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            // フォロー数とフォロワー数を含むユーザー情報を取得
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'bio' => $user->bio,
                'profile_image' => $user->profile_image,
                'created_at' => $user->created_at,
                'followers_count' => $user->loadCount('followers')->followers_count,
                'following_count' => $user->loadCount('following')->following_count,
            ];

            // 誕生日の公開設定をチェック
            if ($user->is_birthday_public) {
                $userData['birthday'] = $user->birthday;
                $userData['is_birthday_public'] = true;
            } else {
                $userData['is_birthday_public'] = false;
            }

            return response()->json($userData);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'ユーザーが見つかりません',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * ユーザー一覧を取得（検索機能付き）
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // 検索パラメータがある場合
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $query->select(['id', 'name', 'username', 'profile_image'])
            ->paginate(20);

        return response()->json($users);
    }

    /**
     * 現在のユーザー情報を取得
     */
    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => '認証が必要です'], 401);
        }

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'bio' => $user->bio,
            'birthday' => $user->birthday,
            'profile_image' => $user->profile_image,
            'is_birthday_public' => $user->is_birthday_public,
            'created_at' => $user->created_at,
            'followers_count' => DB::table('follows')->where('following_id', $user->id)->count(),
            'following_count' => DB::table('follows')->where('follower_id', $user->id)->count(),
        ];

        return response()->json($userData);
    }

    /**
     * プロフィール表示用のユーザー情報を取得（フォロー状態含む）
     */
    public function getProfile(Request $request, string $userId): JsonResponse
    {
        try {
            // $userIdを数値に変換
            $userId = (int) $userId;

            $user = User::withCount(['followers', 'following'])
                ->findOrFail($userId);

            $currentUser = Auth::user();
            $isFollowing = false;

            if ($currentUser && $currentUser->id !== $userId) {
                $isFollowing = DB::table('follows')
                    ->where('follower_id', $currentUser->id)
                    ->where('following_id', $userId)
                    ->exists();
            }

            // 投稿数の詳細を取得
            $postsQuery = DB::table('posts')->where('user_id', $userId);

            // 自分のプロフィールでない場合は、閲覧可能な投稿のみをカウント
            if (!$currentUser || $currentUser->id != $userId) {
                $postsQuery = $postsQuery->where(function ($query) use ($currentUser, $userId) {
                    $query->where('view_permission', 'public');

                    if ($currentUser) {
                        // フォロワー限定の投稿をチェック
                        $isFollower = DB::table('follows')
                            ->where('follower_id', $currentUser->id)
                            ->where('following_id', $userId)
                            ->exists();

                        if ($isFollower) {
                            $query->orWhere('view_permission', 'followers');
                        }

                        // 相互フォローの投稿をチェック
                        $isMutualFollow = $isFollower && DB::table('follows')
                            ->where('follower_id', $userId)
                            ->where('following_id', $currentUser->id)
                            ->exists();

                        if ($isMutualFollow) {
                            $query->orWhere('view_permission', 'mutuals');
                        }
                    }
                });
            }

            // 投稿数の詳細統計を計算
            // 基本となるクエリ条件を関数として定義
            $getBaseQuery = function () use ($currentUser, $userId) {
                $query = DB::table('posts')->where('user_id', $userId);

                // 自分のプロフィールでない場合は、閲覧可能な投稿のみをカウント
                if (!$currentUser || $currentUser->id != $userId) {
                    $query = $query->where(function ($q) use ($currentUser, $userId) {
                        $q->where('view_permission', 'public');

                        if ($currentUser) {
                            // フォロワー限定の投稿をチェック
                            $isFollower = DB::table('follows')
                                ->where('follower_id', $currentUser->id)
                                ->where('following_id', $userId)
                                ->exists();

                            if ($isFollower) {
                                $q->orWhere('view_permission', 'followers');
                            }

                            // 相互フォローの投稿をチェック
                            $isMutualFollow = $isFollower && DB::table('follows')
                                ->where('follower_id', $userId)
                                ->where('following_id', $currentUser->id)
                                ->exists();

                            if ($isMutualFollow) {
                                $q->orWhere('view_permission', 'mutuals');
                            }
                        }
                    });
                }

                return $query;
            };

            $postStats = [
                'total' => $getBaseQuery()->count(),
                'by_content_type' => [
                    'text' => [
                        'free' => $getBaseQuery()->whereIn('content_type', ['text', 'quote'])->where('is_paid', false)->count(),
                        'paid' => $getBaseQuery()->whereIn('content_type', ['text', 'quote'])->where('is_paid', true)->count(),
                    ],
                    'video' => [
                        'free' => $getBaseQuery()->where('content_type', 'video')->where('is_paid', false)->count(),
                        'paid' => $getBaseQuery()->where('content_type', 'video')->where('is_paid', true)->count(),
                    ],
                    'short_video' => [
                        'free' => $getBaseQuery()->where('content_type', 'short_video')->where('is_paid', false)->count(),
                        'paid' => $getBaseQuery()->where('content_type', 'short_video')->where('is_paid', true)->count(),
                    ],
                ],
                'by_payment' => [
                    'free' => $getBaseQuery()->where('is_paid', false)->count(),
                    'paid' => $getBaseQuery()->where('is_paid', true)->count(),
                ]
            ];

            $userData = [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->name,
                'email' => $user->email,
                'bio' => $user->bio,
                'avatar' => $user->profile_image,
                'location' => null, // TODO: 位置情報フィールドが必要な場合は追加
                'birthday' => $user->birthday,
                'birthday_visibility' => $user->is_birthday_public ? 'public' : 'private',
                'created_at' => $user->created_at,
                'followers_count' => $user->followers_count,
                'following_count' => $user->following_count,
                'posts_count' => $postStats['total'],
                'post_stats' => $postStats,
                'is_following' => $isFollowing,
                'is_own_profile' => $currentUser && $currentUser->id == $userId,
            ];

            return response()->json([
                'user' => $userData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'ユーザーが見つかりません'
            ], 404);
        }
    }

    /**
     * ユーザーをフォロー
     */
    public function follow(Request $request, string $userId): JsonResponse
    {
        try {
            $currentUser = Auth::user();
            $targetUser = User::findOrFail($userId);

            // 自分自身をフォローすることはできない
            if ($currentUser->id === $targetUser->id) {
                return response()->json([
                    'message' => '自分自身をフォローすることはできません'
                ], 400);
            }

            // 既にフォローしているかチェック
            $existingFollow = DB::table('follows')
                ->where('follower_id', $currentUser->id)
                ->where('following_id', $userId)
                ->first();

            if ($existingFollow) {
                return response()->json([
                    'message' => '既にフォローしています'
                ], 400);
            }

            // フォロー関係を作成
            DB::table('follows')->insert([
                'follower_id' => $currentUser->id,
                'following_id' => $userId,
                'created_at' => now()
            ]);

            return response()->json([
                'message' => 'フォローしました',
                'is_following' => true
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'フォローに失敗しました'
            ], 500);
        }
    }

    /**
     * ユーザーのフォローを解除
     */
    public function unfollow(Request $request, string $userId): JsonResponse
    {
        try {
            $currentUser = Auth::user();
            $targetUser = User::findOrFail($userId);

            // フォロー関係を削除
            $deleted = DB::table('follows')
                ->where('follower_id', $currentUser->id)
                ->where('following_id', $userId)
                ->delete();

            if ($deleted === 0) {
                return response()->json([
                    'message' => 'フォローしていません'
                ], 400);
            }

            return response()->json([
                'message' => 'フォローを解除しました',
                'is_following' => false
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'フォロー解除に失敗しました'
            ], 500);
        }
    }

    /**
     * ユーザーの投稿一覧を取得
     */
    public function getUserPosts(Request $request, string $userId): JsonResponse
    {
        try {
            // $userIdを数値に変換
            $userId = (int) $userId;

            $user = User::findOrFail($userId);
            $currentUser = Auth::user();

            $postsQuery = $user->posts()
                ->select([
                    'id',
                    'user_id',
                    'view_permission',
                    'comment_permission',
                    'is_sensitive',
                    'content_type',
                    'text_content',
                    'quoted_post_id',
                    'is_paid',
                    'price',
                    'introduction',
                    'created_at',
                    'updated_at'
                ])
                ->with([
                    'user:id,name,username,profile_image',
                    'media',
                    'quotedPost.user:id,name,username,profile_image',
                    'quotedPost.media'
                ])
                ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes']);

            // 自分のプロフィールでない場合は、閲覧可能な投稿のみを取得
            if (!$currentUser || $currentUser->id != $userId) {
                $postsQuery = $postsQuery->where(function ($query) use ($currentUser, $userId) {
                    $query->where('view_permission', 'public');

                    if ($currentUser) {
                        // フォロワー限定の投稿をチェック
                        $isFollower = DB::table('follows')
                            ->where('follower_id', $currentUser->id)
                            ->where('following_id', $userId)
                            ->exists();

                        if ($isFollower) {
                            $query->orWhere('view_permission', 'followers');
                        }

                        // 相互フォローの投稿をチェック
                        $isMutualFollow = $isFollower && DB::table('follows')
                            ->where('follower_id', $userId)
                            ->where('following_id', $currentUser->id)
                            ->exists();

                        if ($isMutualFollow) {
                            $query->orWhere('view_permission', 'mutuals');
                        }
                    }
                });
            }

            $posts = $postsQuery->orderBy('created_at', 'desc')->paginate(20);

            // 各投稿にユーザーのアクション状態を追加
            if ($currentUser) {
                $posts->getCollection()->transform(function ($post) use ($currentUser) {
                    $post->is_liked = $post->actions()
                        ->where('user_id', $currentUser->id)
                        ->where('action_type', 'like')
                        ->exists();

                    $post->is_sparked = $post->actions()
                        ->where('user_id', $currentUser->id)
                        ->where('action_type', 'spark')
                        ->exists();

                    $post->is_bookmarked = $post->actions()
                        ->where('user_id', $currentUser->id)
                        ->where('action_type', 'bookmark')
                        ->exists();

                    return $post;
                });
            }

            return response()->json([
                'data' => $posts->items(),
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'ユーザーの投稿取得に失敗しました'
            ], 404);
        }
    }

    /**
     * ユーザーがいいねした投稿とコメントを取得
     */
    public function getLikedPosts(Request $request, string $id): JsonResponse
    {
        Log::info("getLikedPosts called with user ID: " . $id);

        try {
            $targetUser = User::findOrFail($id);
            $currentUser = Auth::user();

            Log::info("Getting liked posts for user: {$targetUser->id}, current user: " . ($currentUser ? $currentUser->id : 'guest'));

            // 対象ユーザーがいいねした投稿を取得
            $likedPostIds = DB::table('post_actions')
                ->where('user_id', $targetUser->id)
                ->where('action_type', 'like')
                ->pluck('post_id');

            // 対象ユーザーがいいねしたコメントが属する投稿も取得
            $likedCommentPostIds = DB::table('reply_actions')
                ->join('replies', 'reply_actions.reply_id', '=', 'replies.id')
                ->where('reply_actions.user_id', $targetUser->id)
                ->where('reply_actions.action_type', 'like')
                ->pluck('replies.post_id');

            // 両方のIDを統合してユニークにする
            $allPostIds = $likedPostIds->merge($likedCommentPostIds)->unique();

            Log::info("Found liked post IDs: " . $likedPostIds->toJson());
            Log::info("Found liked comment post IDs: " . $likedCommentPostIds->toJson());
            Log::info("All post IDs: " . $allPostIds->toJson());

            // 投稿を取得
            $postsQuery = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media'])
                ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes'])
                ->whereIn('id', $allPostIds)
                ->orderBy('created_at', 'desc');

            // 公開設定による閲覧制限を適用
            if (!$currentUser || (int)$currentUser->id !== (int)$targetUser->id) {
                // 他人のプロフィールの場合、閲覧可能な投稿のみ表示
                $postsQuery->where(function ($query) use ($currentUser, $targetUser) {
                    $query->where('view_permission', 'public');

                    if ($currentUser) {
                        $query->orWhere('view_permission', 'followers');
                        $query->orWhere('view_permission', 'mutuals');
                    }
                });
            }

            $posts = $postsQuery->paginate(20);

            Log::info("Final posts count: " . $posts->count());

            // 各投稿にアクション情報を追加
            if ($currentUser) {
                $posts->getCollection()->transform(function ($post) use ($currentUser) {
                    $post->is_liked = $post->actions()
                        ->where('user_id', $currentUser->id)
                        ->where('action_type', 'like')
                        ->exists();

                    $post->is_sparked = $post->actions()
                        ->where('user_id', $currentUser->id)
                        ->where('action_type', 'spark')
                        ->exists();

                    $post->is_bookmarked = $post->actions()
                        ->where('user_id', $currentUser->id)
                        ->where('action_type', 'bookmark')
                        ->exists();

                    return $post;
                });
            }

            return response()->json([
                'data' => $posts->items(),
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'いいねした投稿の取得に失敗しました'
            ], 404);
        }
    }
}
