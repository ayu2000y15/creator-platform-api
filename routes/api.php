<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TwoFactorAuthController;

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

// Googleログイン
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);


// --- 認証必須のルート ---
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // ログイン中のユーザー情報を取得するエンドポイント
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // 二段階認証の有効化/無効化
    Route::post('/user/two-factor-authentication', [TwoFactorAuthController::class, 'store']);
    Route::delete('/user/two-factor-authentication', [TwoFactorAuthController::class, 'destroy']);
});
