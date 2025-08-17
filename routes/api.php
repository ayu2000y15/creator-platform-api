<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Api\ProfileImageController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostActionController;
use App\Http\Controllers\Api\PostViewController;
use App\Http\Controllers\Api\ReplyController;
use App\Http\Controllers\Api\UserStatsController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\TwoFactorAuthController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
// 接続テスト用
Route::get('/test', function () {
    return response()->json(['message' => 'API接続成功', 'status' => 'ok']);
});

// --- 認証不要のルート ---
Route::post('/register', [RegisteredUserController::class, 'store']);
Route::post('/register/email', [AuthController::class, 'registerWithEmail']);
Route::post('/register/verify', [AuthController::class, 'verifyEmailAndCompleteRegistration']);

Route::post('/login', [AuthController::class, 'login']);
Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);

// ログイン時のメール認証（認証不要）
Route::post('/email-two-factor-code', [AuthController::class, 'sendEmailTwoFactorCode']);
Route::post('/email-two-factor-verify', [AuthController::class, 'verifyEmailTwoFactorCode']);

// Googleログイン
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);

// メール認証完了（認証不要）
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed'])
    ->name('verification.verify');

// --- 投稿・リプライ関連 (認証不要) ---
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);
Route::get('/posts/{post}/replies', [ReplyController::class, 'index']);

// ユーザー情報関連（認証不要）
Route::get('/users/{user}', [UserController::class, 'show']);

// テスト用：認証なしでいいねした投稿を取得
Route::get('/test-likes/{user}', function ($user) {
    return response()->json([
        'message' => 'Test endpoint working',
        'user' => $user,
        'data' => []
    ]);
});

// --- 認証必須のルート ---
Route::middleware('auth:sanctum')->group(function () {
    // ログイン中のユーザー情報を取得するエンドポイント
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // プロフィール関連のルートを追加
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);

    // ユーザー統計データ
    Route::get('/user/stats', [UserStatsController::class, 'getUserStats']);

    // プロフィール画像関連
    Route::post('/user/profile-image', [ProfileImageController::class, 'upload']);
    Route::delete('/user/profile-image', [ProfileImageController::class, 'delete']);

    // メール認証関連
    Route::get('/email/verification-notification', [AuthController::class, 'checkEmailVerification']);
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail']);

    // メール認証による二段階認証
    Route::post('/user/email-two-factor-authentication', [TwoFactorAuthController::class, 'enableEmailTwoFactor']);
    Route::delete('/user/email-two-factor-authentication', [TwoFactorAuthController::class, 'disableEmailTwoFactor']);

    // 二段階認証関連
    Route::post('/user/two-factor-authentication', [TwoFactorAuthController::class, 'store']);
    Route::delete('/user/two-factor-authentication', [TwoFactorAuthController::class, 'destroy']);
    Route::get('/user/two-factor-qr-code', [TwoFactorAuthController::class, 'qrCode']);
    Route::get('/user/two-factor-recovery-codes', [TwoFactorAuthController::class, 'recoveryCodes']);
    Route::post('/user/two-factor-recovery-codes', [TwoFactorAuthController::class, 'regenerateRecoveryCodes']);
    Route::get('/user/two-factor-secret', [TwoFactorAuthController::class, 'getSecret']);
    Route::post('/user/confirmed-two-factor-authentication', [TwoFactorAuthController::class, 'confirm']);

    // 投稿の作成・更新・削除
    Route::post('/posts', [PostController::class, 'store']);
    Route::put('/posts/{post}', [PostController::class, 'update']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

    // 投稿へのアクション
    Route::post('/posts/{post}/like', [PostActionController::class, 'like']);
    Route::delete('/posts/{post}/like', [PostActionController::class, 'unlike']);
    Route::post('/posts/{post}/spark', [PostActionController::class, 'spark']);
    Route::delete('/posts/{post}/spark', [PostActionController::class, 'unspark']);
    Route::post('/posts/{post}/bookmark', [PostActionController::class, 'bookmark']);
    Route::delete('/posts/{post}/bookmark', [PostActionController::class, 'unbookmark']);

    // 閲覧履歴の記録
    Route::post('/posts/{post}/view', [PostViewController::class, 'store']);

    // リプライの作成・更新・削除
    Route::post('/posts/{post}/replies', [ReplyController::class, 'storeToPost']);
    Route::post('/replies/{reply}/replies', [ReplyController::class, 'storeToReply']);
    Route::put('/replies/{reply}', [ReplyController::class, 'update']);
    Route::delete('/replies/{reply}', [ReplyController::class, 'destroy']);

    // リプライへのアクション
    Route::post('/replies/{reply}/like', [ReplyController::class, 'like']);
    Route::delete('/replies/{reply}/like', [ReplyController::class, 'unlike']);
    Route::post('/replies/{reply}/spark', [ReplyController::class, 'spark']);
    Route::delete('/replies/{reply}/spark', [ReplyController::class, 'unspark']);
    Route::post('/replies/{reply}/quote', [ReplyController::class, 'quote']);

    // ユーザープロフィール・フォロー関連
    Route::get('/users/{user}/profile', [UserController::class, 'getProfile']);
    Route::get('/users/{user}/posts', [UserController::class, 'getUserPosts']);
    Route::get('/users/{user}/likes', [UserController::class, 'getLikedPosts']);
    Route::post('/users/{user}/follow', [UserController::class, 'follow']);
    Route::delete('/users/{user}/follow', [UserController::class, 'unfollow']);

    // テスト用: post_actionsテーブルの確認
    Route::get('/debug/post-actions', function () {
        $actions = DB::table('post_actions')->get();
        return response()->json(['actions' => $actions]);
    });
});
