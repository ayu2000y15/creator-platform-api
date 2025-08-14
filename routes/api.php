<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
});
