<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;

class Reply extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'post_id',
        'parent_id',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // appendsからis_likedを削除（コントローラーで手動設定するため）
    protected $appends = ['likes_count', 'sparks_count', 'quotes_count'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Reply::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Reply::class, 'parent_id');
    }

    // 新しいアクション機能のリレーション
    public function replyActions(): HasMany
    {
        return $this->hasMany(ReplyAction::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ReplyAction::class)->where('action_type', 'like');
    }

    public function sparks(): HasMany
    {
        return $this->hasMany(ReplyAction::class)->where('action_type', 'spark');
    }

    public function getLikesCountAttribute(): int
    {
        return $this->likes()->count();
    }

    public function getSparksCountAttribute(): int
    {
        return $this->sparks()->count();
    }

    public function getQuotesCountAttribute(): int
    {
        return Post::where('quoted_reply_id', $this->id)->count();
    }

    // is_likedアクセサーを削除（コントローラーで手動設定するため）
    // public function getIsLikedAttribute(): bool
    // {
    //     return Auth::guard('sanctum')->check() && $this->likedBy()->where('user_id', Auth::guard('sanctum')->id())->exists();
    // }
}
