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
use Illuminate\Validation\Rules\Password;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'メールアドレスまたはパスワードが正しくありません。'], 401);
        }

        // 二段階認証の判定（メール認証を優先）
        if ($user->email_two_factor_enabled) {
            return response()->json([
                'two_factor' => true,
                'two_factor_method' => 'email',
                'message' => 'メール認証による二段階認証が必要です。'
            ]);
        } elseif ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            return response()->json([
                'two_factor' => true,
                'two_factor_method' => 'app',
                'message' => '認証アプリによる二段階認証が必要です。'
            ]);
        }

        // 通常のログイン
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ]);
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
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'ユーザーが見つかりません。'], 404);
        }

        // 二段階認証コードの検証処理
        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey(
            decrypt($user->two_factor_secret),
            $request->code
        );

        if (!$valid) {
            return response()->json(['message' => '認証コードが正しくありません。'], 422);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ]);
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

    /**
     * プロフィール情報を更新
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $request->user()->id,
        ]);

        $user = $request->user();
        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        return response()->json($user);
    }

    /**
     * パスワードを変更
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $user = $request->user();

        // 現在のパスワードを確認
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => '現在のパスワードが正しくありません。'
            ], 422);
        }

        // パスワードを更新
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'パスワードを変更しました。']);
    }

    /**
     * メール認証状態を確認
     */
    public function checkEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'verified' => !is_null($user->email_verified_at)
        ]);
    }

    /**
     * 認証メールを再送信
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'メールアドレスは既に認証済みです。']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => '認証メールを送信しました。']);
    }

    /**
     * メール認証を完了
     */
    public function verifyEmail(Request $request, $id, $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => '無効な認証リンクです。'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'メールアドレスは既に認証済みです。']);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'メールアドレスの認証が完了しました。']);
    }

    public function sendEmailTwoFactorCode(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'ユーザーが見つかりません。'], 404);
        }

        $code = random_int(100000, 999999);
        Cache::put('2fa_email_code_for_user_' . $user->id, $code, now()->addMinutes(10));

        Mail::to($user->email)->send(new TwoFactorCodeMail((string)$code));

        return response()->json(['message' => '認証コードをメールに送信しました。']);
    }

    public function verifyEmailTwoFactorCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|digits:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'ユーザーが見つかりません。'], 404);
        }

        $cachedCode = Cache::get('2fa_email_code_for_user_' . $user->id);

        if (!$cachedCode || $cachedCode != $request->code) {
            return response()->json(['message' => '認証コードが正しくありません。'], 422);
        }

        Cache::forget('2fa_email_code_for_user_' . $user->id);
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }
}
