<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        $filter = $request->query('filter', 'recommend'); // recommend, following, short, paid

        if ($filter === 'following' && $user) {
            // フォロー中のユーザーの投稿＋スパーク（リポスト）を取得（ショート除外）
            return $this->getFollowingFeed($request, $user);
        }

        if ($filter === 'short' && $user) {
            // ショート専用フィード（リポストなし）
            return $this->getShortFeed($request, $user);
        }

        if ($filter === 'recommend') {
            // おすすめ：リポスト投稿も含める
            return $this->getRecommendFeed($request, $user);
        }

        $query = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
            ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views']);

        // センシティブコンテンツのフィルタリング
        if (!$user || !$this->isAdult($user)) {
            $query->where('is_sensitive', false);
        }

        // フィルター別の処理
        switch ($filter) {
            case 'short':
                $query->where('content_type', 'short_video');
                break;
            case 'paid':
                if ($user) {
                    $query->where('is_paid', true);
                } else {
                    return response()->json(['data' => [], 'meta' => []]);
                }
                break;
            default:
                // デフォルト：ショートを除外
                $query->where('content_type', '!=', 'short_video');
                break;
        }

        // 閲覧権限のフィルタリング
        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('view_permission', 'public')
                    ->orWhere(function ($q) use ($user) {
                        $q->where('view_permission', 'followers')
                            ->whereHas('user.followers', function ($q) use ($user) {
                                $q->where('follower_id', $user->id);
                            });
                    })
                    ->orWhere(function ($q) use ($user) {
                        $q->where('view_permission', 'mutuals')
                            ->whereHas('user.followers', function ($q) use ($user) {
                                $q->where('follower_id', $user->id);
                            })
                            ->whereHas('user.following', function ($q) use ($user) {
                                $q->where('following_id', $user->id);
                            });
                    })
                    ->orWhere('user_id', $user->id); // 自分の投稿
            });
        } else {
            $query->where('view_permission', 'public');
        }

        // カーソルベースページネーション（最適化）
        $posts = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc') // 同一時間の投稿の順序を安定化
            ->cursorPaginate(20);

        // ユーザーのアクション状態を効率的に取得
        if ($user) {
            $postIds = collect($posts->items())->pluck('id')->toArray();

            // 一括でアクション状態を取得
            $userActions = DB::table('post_actions')
                ->where('user_id', $user->id)
                ->whereIn('post_id', $postIds)
                ->whereIn('action_type', ['like', 'spark', 'bookmark'])
                ->select('post_id', 'action_type')
                ->get()
                ->groupBy('post_id');

            $posts->through(function ($post) use ($userActions) {
                $actions = $userActions->get($post->id, collect());

                $post->is_liked = $actions->contains('action_type', 'like');
                $post->is_sparked = $actions->contains('action_type', 'spark');
                $post->is_bookmarked = $actions->contains('action_type', 'bookmark');

                return $post;
            });
        }

        return response()->json($posts);
    }

    private function getFollowingFeed(Request $request, $user)
    {
        // フォロー中のユーザーIDを取得
        $followingIds = $user->following()->pluck('users.id')->toArray();
        $followingIds[] = $user->id; // 自分の投稿も含める

        // 1. 通常の投稿（ショートを除外）
        $postsQuery = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
            ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views'])
            ->whereIn('user_id', $followingIds)
            ->where('content_type', '!=', 'short_video'); // ショートを除外

        // 通常の投稿を取得
        $posts = $postsQuery->orderBy('created_at', 'desc')->take(50)->get();

        // 通常の投稿にリポスト情報を追加
        foreach ($posts as $post) {
            $post->is_repost = false;
            $post->repost_user = null;
            $post->repost_created_at = $post->created_at;
        }

        // 2. スパークされた投稿を追加
        $sparkedEntries = collect();

        // フォロー中のユーザーのスパークアクションを取得
        $recentSparks = DB::table('post_actions')
            ->join('posts', 'post_actions.post_id', '=', 'posts.id')
            ->join('users', 'post_actions.user_id', '=', 'users.id')
            ->where('post_actions.action_type', 'spark')
            ->where('posts.content_type', '!=', 'short_video')
            ->whereIn('post_actions.user_id', $followingIds)
            ->select(
                'posts.id as post_id',
                'post_actions.user_id as repost_user_id',
                'users.name as repost_user_name',
                'users.username as repost_user_username',
                'users.profile_image as repost_user_profile_image',
                'post_actions.created_at as repost_created_at'
            )
            ->orderBy('post_actions.created_at', 'desc')
            ->take(50)
            ->get();

        foreach ($recentSparks as $spark) {
            $post = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
                ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views'])
                ->find($spark->post_id);

            if ($post) {
                $post->is_repost = true;
                $post->repost_user = (object) [
                    'id' => $spark->repost_user_id,
                    'name' => $spark->repost_user_name,
                    'username' => $spark->repost_user_username,
                    'profile_image' => $spark->repost_user_profile_image,
                ];
                $post->repost_created_at = $spark->repost_created_at;

                $sparkedEntries->push($post);
            }
        }

        // 投稿とスパークエントリを統合してソート
        $allPosts = $posts->concat($sparkedEntries)->sortByDesc(function ($post) {
            return $post->is_repost ? $post->repost_created_at : $post->created_at;
        })->take(20);

        // ユーザーのアクション状態を効率的に取得
        if ($user) {
            $postIds = $allPosts->pluck('id')->toArray();

            // 一括でアクション状態を取得
            $userActions = DB::table('post_actions')
                ->where('user_id', $user->id)
                ->whereIn('post_id', $postIds)
                ->whereIn('action_type', ['like', 'spark', 'bookmark'])
                ->select('post_id', 'action_type')
                ->get()
                ->groupBy('post_id');

            $allPosts->transform(function ($post) use ($userActions) {
                $actions = $userActions->get($post->id, collect());

                $post->is_liked = $actions->contains('action_type', 'like');
                $post->is_sparked = $actions->contains('action_type', 'spark');
                $post->is_bookmarked = $actions->contains('action_type', 'bookmark');

                return $post;
            });
        }

        return response()->json([
            'data' => $allPosts->values(),
            'meta' => [
                'has_more' => $allPosts->count() >= 20
            ]
        ]);
    }

    private function getShortFeed(Request $request, $user)
    {
        // フォロー中のユーザーIDを取得
        $followingIds = $user->following()->pluck('users.id')->toArray();
        $followingIds[] = $user->id; // 自分の投稿も含める

        // ショート投稿のみ（リポストは除外）
        $query = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
            ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views'])
            ->whereIn('user_id', $followingIds)
            ->where('content_type', 'short_video'); // ショートのみ

        // カーソルベースページネーション（最適化）
        $posts = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc') // 同一時間の投稿の順序を安定化
            ->cursorPaginate(20);

        // ユーザーのアクション状態を効率的に取得
        if ($user) {
            $postIds = collect($posts->items())->pluck('id')->toArray();

            // 一括でアクション状態を取得
            $userActions = DB::table('post_actions')
                ->where('user_id', $user->id)
                ->whereIn('post_id', $postIds)
                ->whereIn('action_type', ['like', 'spark', 'bookmark'])
                ->select('post_id', 'action_type')
                ->get()
                ->groupBy('post_id');

            $posts->through(function ($post) use ($userActions) {
                $actions = $userActions->get($post->id, collect());

                $post->is_liked = $actions->contains('action_type', 'like');
                $post->is_sparked = $actions->contains('action_type', 'spark');
                $post->is_bookmarked = $actions->contains('action_type', 'bookmark');

                return $post;
            });
        }

        return response()->json($posts);
    }

    public function show(Request $request, Post $post)
    {
        // Sanctumガードを使用して認証状態を取得（オプション認証）
        $user = Auth::guard('sanctum')->user();

        // デバッグログ追加
        Log::info('Post show request', [
            'post_id' => $post->id,
            'post_user_id' => $post->user_id,
            'current_user_id' => $user ? $user->id : null,
            'user_authenticated' => $user ? true : false,
            'view_permission' => $post->view_permission,
            'is_sensitive' => $post->is_sensitive,
            'auth_header' => $request->header('Authorization') ? 'present' : 'missing'
        ]);

        // 年齢確認
        if ($post->is_sensitive && (!$user || !$this->isAdult($user))) {
            Log::info('Post blocked due to age restriction', ['post_id' => $post->id]);
            return response()->json(['message' => 'このコンテンツは18歳以上のみ閲覧可能です。'], 403);
        }

        // 閲覧権限確認
        $canView = $this->canViewPost($post, $user);
        Log::info('Permission check result', [
            'post_id' => $post->id,
            'can_view' => $canView,
            'is_own_post' => $user && $post->user_id === $user->id
        ]);

        if (!$canView) {
            return response()->json(['message' => '閲覧権限がありません。'], 403);
        }

        $post->load(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
            ->loadCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views']);

        // ユーザーのアクション状態を追加
        if ($user) {
            $post->is_liked = $post->postActions()
                ->where('user_id', $user->id)
                ->where('action_type', 'like')
                ->exists();

            $post->is_sparked = $post->postActions()
                ->where('user_id', $user->id)
                ->where('action_type', 'spark')
                ->exists();

            $post->is_bookmarked = $post->postActions()
                ->where('user_id', $user->id)
                ->where('action_type', 'bookmark')
                ->exists();
        }

        return response()->json($post);
    }

    public function store(Request $request)
    {
        // デバッグ用：リクエストデータをログ出力
        Log::info('Post creation request data:', [
            'content_type' => $request->content_type,
            'quoted_post_id' => $request->quoted_post_id,
            'quoted_reply_id' => $request->quoted_reply_id,
            'all_data' => $request->all()
        ]);

        $request->validate([
            'content_type' => 'required|in:text,video,short_video,quote',
            'text_content' => 'nullable|string|max:140',
            'view_permission' => 'required|in:public,followers,mutuals',
            'comment_permission' => 'required|in:public,followers,mutuals',
            'is_sensitive' => 'boolean',
            'quoted_post_id' => 'nullable|exists:posts,id',
            'quoted_reply_id' => 'nullable|exists:replies,id',
            'is_paid' => 'boolean',
            'price' => 'nullable|integer|min:1',
            'introduction' => 'nullable|string',
            'media' => 'nullable|array|max:4',
            'media.*' => 'file|mimes:jpeg,png,jpg,gif,mp4,mov|max:5242880', // 5GB
        ]);

        DB::beginTransaction();
        try {
            $post = Post::create([
                'id' => Str::uuid(),
                'user_id' => Auth::id(),
                'content_type' => $request->content_type,
                'text_content' => $request->text_content,
                'view_permission' => $request->view_permission,
                'comment_permission' => $request->comment_permission,
                'is_sensitive' => $request->boolean('is_sensitive'),
                'quoted_post_id' => $request->quoted_post_id,
                'quoted_reply_id' => $request->quoted_reply_id,
                'is_paid' => $request->boolean('is_paid'),
                'price' => $request->price,
                'introduction' => $request->introduction,
            ]);

            // メディアファイルの処理
            if ($request->hasFile('media')) {
                $uploadResults = ImageUploadService::uploadMultipleImages($request->file('media'), 'post_media');

                foreach ($uploadResults as $index => $result) {
                    if ($result['success']) {
                        $file = $request->file('media')[$index];
                        $post->media()->create([
                            'id' => Str::uuid(),
                            'file_path' => $result['url'],
                            'file_type' => $file->getMimeType(),
                            'order' => $index + 1,
                        ]);
                    }
                }
            }

            DB::commit();

            $post->load(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
                ->loadCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views']);

            // デバッグ用：コメント引用投稿の場合、リレーションを確認
            if ($post->quoted_reply_id) {
                Log::info('Comment quote post created', [
                    'post_id' => $post->id,
                    'quoted_reply_id' => $post->quoted_reply_id,
                    'quoted_reply_loaded' => $post->quoted_reply ? 'yes' : 'no',
                    'quoted_reply_post_loaded' => $post->quoted_reply && $post->quoted_reply->post ? 'yes' : 'no'
                ]);
            }

            // 新しい投稿なので全てのアクション状態はfalse
            $post->is_liked = false;
            $post->is_sparked = false;
            $post->is_bookmarked = false;

            return response()->json($post, 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => '投稿の作成に失敗しました。'], 500);
        }
    }

    public function update(Request $request, Post $post)
    {
        if ($post->user_id !== $request->user()->id) {
            return response()->json(['message' => '権限がありません。'], 403);
        }

        $request->validate([
            'text_content' => 'nullable|string|max:140',
            'view_permission' => 'required|in:public,followers,mutuals',
            'comment_permission' => 'required|in:public,followers,mutuals',
            'is_sensitive' => 'boolean',
            'price' => 'nullable|integer|min:1',
            'introduction' => 'nullable|string',
        ]);

        $post->update($request->only([
            'text_content',
            'view_permission',
            'comment_permission',
            'is_sensitive',
            'price',
            'introduction',
        ]));

        $post->load(['user', 'media'])
            ->loadCount(['likes', 'sparks', 'bookmarks', 'replies', 'views']);

        // ユーザーのアクション状態を追加
        $user = Auth::user();
        $post->is_liked = $post->postActions()
            ->where('user_id', $user->id)
            ->where('action_type', 'like')
            ->exists();

        $post->is_sparked = $post->postActions()
            ->where('user_id', $user->id)
            ->where('action_type', 'spark')
            ->exists();

        $post->is_bookmarked = $post->postActions()
            ->where('user_id', $user->id)
            ->where('action_type', 'bookmark')
            ->exists();

        return response()->json($post);
    }

    public function destroy(Post $post)
    {
        Log::info('Deleting post', ['post_id' => $post->id, 'user_id' => Auth::id()]);
        // Sanctumガードで認証されたユーザーを取得
        $user = Auth::guard('sanctum')->user();

        // そもそもユーザーが認証されていない場合のエラー（401 Unauthorized）
        if (!$user) {
            return response()->json(['message' => '認証されていません。'], 401);
        }

        // 投稿の所有者かどうかをチェック
        if ($post->user_id !== $user->id) {
            return response()->json(['message' => '権限がありません。'], 403);
        }

        DB::beginTransaction();
        try {
            // メディアファイルを削除
            foreach ($post->media as $media) {
                ImageUploadService::deleteImage($media->file_path, 'post_media');
            }

            // 投稿を削除
            $post->delete();

            DB::commit();
            return response()->json(['message' => '投稿を削除しました。']);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => '投稿の削除に失敗しました。'], 500);
        }
    }

    private function isAdult(User $user): bool
    {
        if (!$user->birthday) {
            return false;
        }

        return Carbon::parse($user->birthday)->age >= 18;
    }

    private function canViewPost(Post $post, ?User $user): bool
    {
        Log::info('canViewPost called', [
            'post_id' => $post->id,
            'post_user_id' => $post->user_id,
            'current_user_id' => $user ? $user->id : null,
            'view_permission' => $post->view_permission
        ]);

        switch ($post->view_permission) {
            case 'public':
                Log::info('Public post - allowing access');
                return true;
            case 'followers':
                $isOwner = $user && $post->user_id === $user->id;
                Log::info('Followers post', ['is_owner' => $isOwner]);
                return $user && ($post->user_id === $user->id ||
                    $post->user->followers()->where('follower_id', $user->id)->exists());
            case 'mutuals':
                $isOwner = $user && $post->user_id === $user->id;
                Log::info('Mutuals post', ['is_owner' => $isOwner]);
                return $user && ($post->user_id === $user->id ||
                    ($post->user->followers()->where('follower_id', $user->id)->exists() &&
                        $post->user->following()->where('following_id', $user->id)->exists()));
            default:
                Log::info('Unknown view permission', ['permission' => $post->view_permission]);
                return false;
        }
    }

    private function getRecommendFeed(Request $request, $user)
    {
        // 1. 通常の投稿（ショートを除外）
        $postsQuery = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
            ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views'])
            ->where('content_type', '!=', 'short_video'); // ショートを除外

        // センシティブコンテンツのフィルタリング
        if (!$user || !$this->isAdult($user)) {
            $postsQuery->where('is_sensitive', false);
        }

        // 閲覧権限のフィルタリング
        if ($user) {
            $postsQuery->where(function ($q) use ($user) {
                $q->where('view_permission', 'public')
                    ->orWhere(function ($q) use ($user) {
                        $q->where('view_permission', 'followers')
                            ->whereHas('user.followers', function ($q) use ($user) {
                                $q->where('follower_id', $user->id);
                            });
                    })
                    ->orWhere(function ($q) use ($user) {
                        $q->where('view_permission', 'mutuals')
                            ->whereHas('user.followers', function ($q) use ($user) {
                                $q->where('follower_id', $user->id);
                            })
                            ->whereHas('user.following', function ($q) use ($user) {
                                $q->where('following_id', $user->id);
                            });
                    })
                    ->orWhere('user_id', $user->id); // 自分の投稿
            });
        } else {
            $postsQuery->where('view_permission', 'public');
        }

        // 通常の投稿を取得
        $posts = $postsQuery->orderBy('created_at', 'desc')->take(50)->get();

        // 通常の投稿にリポスト情報を追加
        foreach ($posts as $post) {
            $post->is_repost = false;
            $post->repost_user = null;
            $post->repost_created_at = $post->created_at;
        }

        // 2. スパークされた投稿を追加（重複を避けるため、元の投稿とは別のタイムラインエントリとして扱う）
        $sparkedEntries = collect();

        if ($user) {
            // 最近のスパークアクションを取得
            $recentSparks = DB::table('post_actions')
                ->join('posts', 'post_actions.post_id', '=', 'posts.id')
                ->join('users', 'post_actions.user_id', '=', 'users.id')
                ->where('post_actions.action_type', 'spark')
                ->where('posts.content_type', '!=', 'short_video')
                ->select(
                    'posts.id as post_id',
                    'post_actions.user_id as repost_user_id',
                    'users.name as repost_user_name',
                    'users.username as repost_user_username',
                    'users.profile_image as repost_user_profile_image',
                    'post_actions.created_at as repost_created_at'
                )
                ->orderBy('post_actions.created_at', 'desc')
                ->take(50)
                ->get();

            foreach ($recentSparks as $spark) {
                $post = Post::with(['user', 'media', 'quotedPost.user', 'quotedPost.media', 'quotedReply.user', 'quotedReply.post.user'])
                    ->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'quotes', 'views'])
                    ->find($spark->post_id);

                if ($post && $this->canViewPost($post, $user)) {
                    $post->is_repost = true;
                    $post->repost_user = (object) [
                        'id' => $spark->repost_user_id,
                        'name' => $spark->repost_user_name,
                        'username' => $spark->repost_user_username,
                        'profile_image' => $spark->repost_user_profile_image,
                    ];
                    $post->repost_created_at = $spark->repost_created_at;

                    $sparkedEntries->push($post);
                }
            }
        }

        // 投稿とスパークエントリを統合してソート
        $allPosts = $posts->concat($sparkedEntries)->sortByDesc(function ($post) {
            return $post->is_repost ? $post->repost_created_at : $post->created_at;
        })->take(20);

        // ユーザーのアクション状態を効率的に取得
        if ($user) {
            $postIds = $allPosts->pluck('id')->toArray();

            // 一括でアクション状態を取得
            $userActions = DB::table('post_actions')
                ->where('user_id', $user->id)
                ->whereIn('post_id', $postIds)
                ->whereIn('action_type', ['like', 'spark', 'bookmark'])
                ->select('post_id', 'action_type')
                ->get()
                ->groupBy('post_id');

            $allPosts->transform(function ($post) use ($userActions) {
                $actions = $userActions->get($post->id, collect());

                $post->is_liked = $actions->contains('action_type', 'like');
                $post->is_sparked = $actions->contains('action_type', 'spark');
                $post->is_bookmarked = $actions->contains('action_type', 'bookmark');

                return $post;
            });
        }

        return response()->json([
            'data' => $allPosts->values(),
            'meta' => [
                'has_more' => $allPosts->count() >= 20
            ]
        ]);
    }
}
