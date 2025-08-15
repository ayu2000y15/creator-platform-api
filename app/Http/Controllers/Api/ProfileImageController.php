<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ProfileImageController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB
            ]);

            $user = Auth::user();
            $image = $request->file('image');

            // 古い画像を削除
            $this->deleteOldProfileImage($user);

            // ファイル名を生成
            $filename = 'profile_' . $user->id . '_' . time() . '.' . $image->getClientOriginalExtension();

            // S3にファイルを保存 (ディスクを'profile_images'に指定)
            // 'profile-images' ディレクトリに保存されます
            $path = $image->storeAs('', $filename, 'profile_images');

            // S3のURLを取得
            $imageUrl = Storage::disk('profile_images')->url($path);

            $normalizedUrl = str_replace('%5C', '/', $imageUrl);

            // ユーザーのプロフィール画像を更新
            $user->update(['profile_image' => $normalizedUrl]);

            Log::info('Profile image uploaded successfully to S3', [
                'user_id' => $user->id,
                'filename' => $filename,
                'path' => $path,
                'url' => $normalizedUrl
            ]);

            return response()->json([
                'message' => 'プロフィール画像をアップロードしました',
                'image_url' => $normalizedUrl
            ]);
        } catch (\Exception $e) {
            Log::error('Profile image upload failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => '画像のアップロードに失敗しました'
            ], 500);
        }
    }

    public function delete()
    {
        try {
            $user = Auth::user();

            // 古い画像を削除
            $this->deleteOldProfileImage($user);

            // ユーザーのプロフィール画像をクリア
            $user->update(['profile_image' => null]);

            Log::info('Profile image deleted successfully from S3', [
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'プロフィール画像を削除しました'
            ]);
        } catch (\Exception $e) {
            Log::error('Profile image deletion failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => '画像の削除に失敗しました'
            ], 500);
        }
    }

    private function deleteOldProfileImage($user)
    {
        if ($user->profile_image) {
            // S3の完全なURL (例: https://.../profile-images/image.jpg)
            $fullUrlPath = parse_url($user->profile_image, PHP_URL_PATH);

            // 先頭のスラッシュを削除してフルのパスを取得 (例: profile-images/image.jpg)
            $fullPath = ltrim($fullUrlPath, '/');

            // 'profile_images'ディスクのルートパス('profile-images/')を削除し、
            // ファイル名のみの相対パスを取得する (例: image.jpg)
            $relativePath = str_replace('profile-images/', '', $fullPath);

            // 正しい相対パスでファイルの存在を確認
            if (Storage::disk('profile_images')->exists($relativePath)) {

                // ファイルを削除
                Storage::disk('profile_images')->delete($relativePath);

                Log::info('Old profile image deleted from S3', [
                    'user_id' => $user->id,
                    'path' => $relativePath
                ]);
            }
        }
    }
}
