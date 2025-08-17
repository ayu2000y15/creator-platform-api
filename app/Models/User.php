<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'phone_number',
        'birthday',
        'two_factor_confirmed_at',
        'email_two_factor_enabled',
        'profile_image',
        'username',
        'bio',
        'birthday_visibility',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'birthday' => 'date',
            'password' => 'hashed',
            'email_two_factor_enabled' => 'boolean',
        ];
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new class extends VerifyEmail {
            public function toMail($notifiable)
            {
                $verificationUrl = $this->verificationUrl($notifiable);

                return (new MailMessage)
                    ->subject('メールアドレスの認証')
                    ->view('emails.verify-email', [
                        'user' => $notifiable,
                        'verificationUrl' => $verificationUrl
                    ]);
            }
        });
    }

    // フォロー関係
    public function followers()
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')
            ->withTimestamps();
    }

    public function following()
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')
            ->withTimestamps();
    }

    // 投稿関係
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function replies()
    {
        return $this->hasMany(Reply::class);
    }

    public function postActions()
    {
        return $this->hasMany(PostAction::class);
    }

    public function postViews()
    {
        return $this->hasMany(PostView::class);
    }

    public function replyActions()
    {
        return $this->hasMany(ReplyAction::class);
    }

    public function likedReplies()
    {
        return $this->belongsToMany(Reply::class, 'reply_actions', 'user_id', 'reply_id')
            ->where('action_type', 'like');
    }
}
