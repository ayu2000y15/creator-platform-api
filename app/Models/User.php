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
        'two_factor_confirmed_at',
        'email_two_factor_enabled',
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
}
