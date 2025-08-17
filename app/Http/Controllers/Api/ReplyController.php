<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Reply;
use App\Models\ReplyAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ReplyController extends Controller
{
    public function index(Post $post)
    {
        $user = Auth::guard('sanctum')->user();

        // 投稿に関連するすべてのリプライを取得（親子関係を含む）
        $replies = Reply::with(['user'])
            ->where('post_id', $post->id)
            ->orderBy('created_at', 'asc')
            ->get();

        // 現在のユーザーのアクション状態を一度に取得
        $userActions = [];
        if ($user) {
            $userActionsData = ReplyAction::where('user_id', $user->id)
                ->whereIn('reply_id', $replies->pluck('id'))
                ->get();

            foreach ($userActionsData as $action) {
                $userActions[$action->reply_id][$action->action_type] = true;
            }
        }

        // 各リプライにアクション状態とカウントを追加
        $replies = $replies->map(function ($reply) use ($userActions, $user) {
            $reply->is_liked = $userActions[$reply->id]['like'] ?? false;
            $reply->is_sparked = $userActions[$reply->id]['spark'] ?? false;
            $reply->likes_count = $reply->likes()->count();
            $reply->sparks_count = $reply->sparks()->count();
            // quotes_countはモデルのappendedプロパティから自動取得される
            return $reply;
        });

        // フロントエンドでツリー構造に変換するため、全てのリプライを返す
        return response()->json($replies);
    }

    public function storeToPost(Request $request, Post $post)
    {
        $user = Auth::guard('sanctum')->user();

        // コメント権限チェック
        if (!$this->canComment($post, $user)) {
            return response()->json(['message' => 'コメント権限がありません。'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $reply = Reply::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'post_id' => $post->id,
            'parent_id' => null,
            'content' => $request->content,
        ]);

        $reply->load('user');

        return response()->json($reply, 201);
    }

    public function storeToReply(Request $request, Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        // 元の投稿のコメント権限チェック
        if (!$this->canComment($reply->post, $user)) {
            return response()->json(['message' => 'コメント権限がありません。'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $newReply = Reply::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'post_id' => $reply->post_id,
            'parent_id' => $reply->id,
            'content' => $request->content,
        ]);

        $newReply->load('user');

        return response()->json($newReply, 201);
    }

    public function update(Request $request, Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        if ($reply->user_id !== $user->id) {
            return response()->json(['message' => '権限がありません。'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:1000',
        ]);

        $reply->update([
            'content' => $request->content,
        ]);

        $reply->load('user');

        return response()->json($reply);
    }

    public function destroy(Reply $reply)
    {
        Log::info('Deleting reply', ['post_id' => $reply->id, 'user_id' => Auth::id()]);

        $user = Auth::guard('sanctum')->user();

        if ($reply->user_id !== $user->id) {
            return response()->json(['message' => '権限がありません。'], 403);
        }

        $reply->delete();

        return response()->json(['message' => 'リプライを削除しました。']);
    }

    public function like(Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        // すでにいいねしている場合は何もしない
        $existing = ReplyAction::where([
            'user_id' => $user->id,
            'reply_id' => $reply->id,
            'action_type' => 'like'
        ])->first();

        if ($existing) {
            return response()->json(['message' => 'すでにいいねしています。'], 400);
        }

        ReplyAction::create([
            'user_id' => $user->id,
            'reply_id' => $reply->id,
            'action_type' => 'like',
        ]);

        return response()->json(['message' => 'いいねしました。'], 201);
    }

    public function unlike(Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        $deleted = ReplyAction::where([
            'user_id' => $user->id,
            'reply_id' => $reply->id,
            'action_type' => 'like'
        ])->delete();

        $message = $deleted ? 'いいねを取り消しました。' : '既にいいねを取り消し済みです。';
        return response()->json(['message' => $message]);
    }

    public function spark(Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        // すでにスパークしている場合は何もしない
        $existing = ReplyAction::where([
            'user_id' => $user->id,
            'reply_id' => $reply->id,
            'action_type' => 'spark'
        ])->first();

        if ($existing) {
            return response()->json(['message' => 'すでにスパークしています。'], 400);
        }

        ReplyAction::create([
            'user_id' => $user->id,
            'reply_id' => $reply->id,
            'action_type' => 'spark',
        ]);

        // コメントのスパークをリポストとして投稿を作成
        $originalPost = $reply->post;

        $repostContent = $reply->user->name . "さんのコメントをスパークしました\n\n「" . $reply->content . "」\n\n元の投稿：";

        $repost = Post::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'view_permission' => 'public',
            'comment_permission' => 'public',
            'is_sensitive' => false,
            'content_type' => 'quote',
            'text_content' => $repostContent,
            'quoted_post_id' => $originalPost->id,
        ]);

        $repost->load(['user', 'quotedPost.user']);

        return response()->json([
            'message' => 'スパークし、リポストしました。',
            'repost' => $repost
        ]);
    }

    public function unspark(Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        $deleted = ReplyAction::where([
            'user_id' => $user->id,
            'reply_id' => $reply->id,
            'action_type' => 'spark'
        ])->delete();

        $message = $deleted ? 'スパークを取り消しました。' : '既にスパークを取り消し済みです。';
        return response()->json(['message' => $message]);
    }

    public function quote(Request $request, Reply $reply)
    {
        $user = Auth::guard('sanctum')->user();

        $request->validate([
            'text_content' => 'required|string|max:140',
            'view_permission' => 'required|in:public,followers,mutuals',
            'comment_permission' => 'required|in:public,followers,mutuals',
            'is_sensitive' => 'boolean',
        ]);

        // 引用投稿を作成（リプライを引用する場合は元の投稿を引用対象とする）
        $quotedPost = $reply->post;

        $post = Post::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'view_permission' => $request->view_permission,
            'comment_permission' => $request->comment_permission,
            'is_sensitive' => $request->is_sensitive ?? false,
            'content_type' => 'quote',
            'text_content' => $request->text_content,
            'quoted_post_id' => $quotedPost->id,
            'quoted_reply_id' => $reply->id,
        ]);

        $post->load(['user', 'quotedPost.user', 'quotedReply.user']);

        return response()->json([
            'message' => 'リプライを引用しました。',
            'post' => $post
        ], 201);
    }

    private function canComment(Post $post, User $user): bool
    {
        switch ($post->comment_permission) {
            case 'public':
                return true;
            case 'followers':
                return $post->user_id === $user->id ||
                    $post->user->followers()->where('follower_id', $user->id)->exists();
            case 'mutuals':
                return $post->user_id === $user->id ||
                    ($post->user->followers()->where('follower_id', $user->id)->exists() &&
                        $post->user->following()->where('following_id', $user->id)->exists());
            default:
                return false;
        }
    }
}
