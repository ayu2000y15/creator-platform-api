<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProfileImageController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB
            ]);

            /** @var \App\Models\User $user */
            $user = Auth::user();
            $image = $request->file('image');

            // Delete the existing profile image if it exists
            if ($user->profile_image) {
                ImageUploadService::deleteImage($user->profile_image, 'profile_images');
            }

            // Upload the new image
            $uploadResult = ImageUploadService::uploadImage($image, 'profile_images');

            if (!$uploadResult['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'アップロードに失敗しました: ' . $uploadResult['error']
                ], 500);
            }

            // Update the user's profile image with the URL
            $user->update(['profile_image' => $uploadResult['url']]);

            return response()->json([
                'status' => 'success',
                'message' => 'プロフィール画像を更新しました。',
                'profile_image' => $uploadResult['url']
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'バリデーションエラーが発生しました。',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Profile image upload failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'プロフィール画像のアップロードに失敗しました。'
            ], 500);
        }
    }

    public function delete()
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();

            // Delete the existing profile image if it exists
            if ($user->profile_image) {
                ImageUploadService::deleteImage($user->profile_image, 'profile_images');
            }

            // Clear the user's profile image
            $user->update(['profile_image' => null]);

            return response()->json([
                'status' => 'success',
                'message' => 'プロフィール画像を削除しました。'
            ]);
        } catch (\Exception $e) {
            Log::error('Profile image deletion failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'プロフィール画像の削除に失敗しました。'
            ], 500);
        }
    }
}
