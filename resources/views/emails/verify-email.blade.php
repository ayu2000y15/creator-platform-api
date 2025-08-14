<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>メールアドレスの認証</title>
</head>

<body>
    <h2>メールアドレスの認証</h2>
    <p>{{ $user->name }} 様</p>
    <p>アカウント登録ありがとうございます。以下のボタンをクリックして、メールアドレスの認証を完了してください。</p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $verificationUrl }}"
            style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">
            メールアドレスを認証する
        </a>
    </div>

    <p>このリンクは24時間有効です。</p>
    <p>もしこのメールに心当たりがない場合は、無視してください。</p>

    <hr>
    <p><small>{{ config('app.name') }}</small></p>
</body>

</html>