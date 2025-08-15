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

            // ローカルストレージに保存
            $path = $image->storeAs('profile-images', $filename, 'public');

            // URLを生成
            $imageUrl = Storage::url($path);

            // ユーザーのプロフィール画像を更新
            $user->update(['profile_image' => $imageUrl]);

            Log::info('Profile image uploaded successfully', [
                'user_id' => $user->id,
                'filename' => $filename,
                'path' => $path,
                'url' => $imageUrl
            ]);

            return response()->json([
                'message' => 'プロフィール画像をアップロードしました',
                'image_url' => $imageUrl
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

            Log::info('Profile image deleted successfully', [
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
            // URLからパスを抽出
            $path = str_replace('/storage/', '', $user->profile_image);

            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                Log::info('Old profile image deleted', [
                    'user_id' => $user->id,
                    'path' => $path
                ]);
            }
        }
    }
}
