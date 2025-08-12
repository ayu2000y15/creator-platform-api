<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use App\Mail\TwoFactorCodeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /**
     * メールアドレスとパスワードでログインし、APIトークンを発行する
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['メールアドレスまたはパスワードが正しくありません。'],
            ]);
        }

        // --- 二段階認証のロジックを実装 ---
        if ($user->two_factor_confirmed_at) {
            // 6桁の認証コードを生成
            $code = random_int(100000, 999999);

            // ユーザーIDをキーにして、コードを10分間キャッシュに保存
            Cache::put('2fa_code_for_user_' . $user->id, $code, now()->addMinutes(10));

            // 認証コードをメールで送信
            Mail::to($user->email)->send(new TwoFactorCodeMail((string)$code));

            return response()->json(['two_factor' => true]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }

    /**
     * ユーザーをログアウトさせ、APIトークンを無効化する
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '正常にログアウトしました。']);
    }

    /**
     * Googleの認証ページへリダイレクトする
     */
    public function googleRedirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Googleからのコールバックを処理し、ログインさせてAPIトークンを発行する
     */
    public function googleCallback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::updateOrCreate([
            'google_id' => $googleUser->id,
        ], [
            'name' => $googleUser->name,
            'email' => $googleUser->email,
            'password' => Hash::make(str()->random(24)),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        // フロントエンドの特定URLにトークンを付けてリダイレクト
        return redirect(config('app.frontend_url', '/') . '/auth/callback?token=' . $token);
    }

    /**
     * 二段階認証コードを検証する（将来的に実装）
     */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email', // どのユーザーのコードか特定するためにemailを追加
            'code' => 'required|string|digits:6',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $cacheKey = '2fa_code_for_user_' . $user->id;

        $cachedCode = Cache::get($cacheKey);

        if (! $cachedCode || $cachedCode != $request->code) {
            return response()->json(['message' => '認証コードが正しくありません。'], 422);
        }

        // 認証成功後、キャッシュからコードを削除
        Cache::forget($cacheKey);

        $token = $user->createToken('api-token')->plainTextToken;
        return response()->json(['token' => $token, 'user' => $user]);
    }

    /**
     * メールアドレスのみで仮登録し、確認コードを送信
     */
    public function registerWithEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|unique:users',
        ]);

        // 6桁の確認コードを生成
        $code = random_int(100000, 999999);

        // メールアドレスをキーにして、コードを10分間キャッシュに保存
        Cache::put('registration_code_for_' . $request->email, $code, now()->addMinutes(10));

        // 確認コードをメールで送信
        Mail::to($request->email)->send(new TwoFactorCodeMail((string)$code));

        return response()->json(['message' => '確認コードをメールに送信しました。']);
    }

    /**
     * メール確認コードを検証してユーザー登録を完了
     */
    public function verifyEmailAndCompleteRegistration(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string|digits:6',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $cacheKey = 'registration_code_for_' . $request->email;
        $cachedCode = Cache::get($cacheKey);

        if (!$cachedCode || $cachedCode != $request->code) {
            return response()->json(['message' => '確認コードが正しくありません。'], 422);
        }

        // メールアドレスの重複チェック（念のため）
        if (User::where('email', $request->email)->exists()) {
            return response()->json(['message' => 'このメールアドレスは既に使用されています。'], 422);
        }

        // ユーザーを作成
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(), // メール確認済みとして設定
        ]);

        // 確認コードをキャッシュから削除
        Cache::forget($cacheKey);

        // APIトークンを発行
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }
}
