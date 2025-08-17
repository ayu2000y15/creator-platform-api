<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'view_permission',
        'comment_permission',
        'is_sensitive',
        'content_type',
        'text_content',
        'quoted_post_id',
        'quoted_reply_id',
        'is_paid',
        'price',
        'introduction',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
        'is_paid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('order');
    }

    public function views(): HasMany
    {
        return $this->hasMany(PostView::class);
    }

    /**
     * この投稿のすべてのアクションを取得するリレーション
     */
    public function postActions(): HasMany
    {
        return $this->hasMany(PostAction::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PostAction::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostAction::class)->where('action_type', 'like');
    }

    public function sparks(): HasMany
    {
        return $this->hasMany(PostAction::class)->where('action_type', 'spark');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(PostAction::class)->where('action_type', 'bookmark');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class);
    }

    public function quotedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'quoted_post_id');
    }

    public function quotedReply(): BelongsTo
    {
        return $this->belongsTo(Reply::class, 'quoted_reply_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Post::class, 'quoted_post_id');
    }

    public function scopePublic($query)
    {
        return $query->where('view_permission', 'public');
    }

    public function scopeNotSensitive($query)
    {
        return $query->where('is_sensitive', false);
    }

    public function scopeWithCounts($query)
    {
        return $query->withCount(['likes', 'sparks', 'bookmarks', 'replies', 'views']);
    }
}
