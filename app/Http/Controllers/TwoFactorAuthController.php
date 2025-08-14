<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

class TwoFactorAuthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        $user->forceFill([
            'two_factor_secret' => encrypt($google2fa->generateSecretKey()),
        ])->save();

        return response()->json(['message' => '二段階認証を有効にしました。']);
    }

    public function qrCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json(['message' => '二段階認証が設定されていません。'], 404);
        }

        $google2fa = new Google2FA();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            decrypt($user->two_factor_secret)
        );

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(200),
                new SvgImageBackEnd()
            )
        );

        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        return response()->json(['svg' => $qrCodeSvg]);
    }

    public function getSecret(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json(['message' => '二段階認証が設定されていません。'], 404);
        }

        return response()->json([
            'secret' => decrypt($user->two_factor_secret)
        ]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->two_factor_recovery_codes) {
            $codes = collect(range(1, 8))->map(function () {
                return strtoupper(Str::random(10));
            });

            $user->forceFill([
                'two_factor_recovery_codes' => encrypt($codes->toArray())
            ])->save();
        }

        return response()->json([
            'recovery_codes' => decrypt($user->two_factor_recovery_codes)
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => '二段階認証を無効にしました。']);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|digits:6',
        ]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json(['message' => '二段階認証が設定されていません。'], 400);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey(
            decrypt($user->two_factor_secret),
            $request->code
        );

        if (!$valid) {
            return response()->json(['message' => '認証コードが正しくありません。'], 422);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json(['message' => '二段階認証が正常に設定されました。']);
    }

    public function enableEmailTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'email_two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json(['message' => 'メール認証による二段階認証を有効にしました。']);
    }

    public function disableEmailTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'email_two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => 'メール認証による二段階認証を無効にしました。']);
    }
}
