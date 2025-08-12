<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TwoFactorAuthController extends Controller
{
    // 二段階認証を有効化
    public function store(Request $request)
    {
        $user = $request->user();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json(['status' => 'two-factor-authentication-enabled']);
    }

    // 二段階認証を無効化
    public function destroy(Request $request)
    {
        $user = $request->user();
        $user->forceFill([
            'two_factor_confirmed_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json(['status' => 'two-factor-authentication-disabled']);
    }
}
