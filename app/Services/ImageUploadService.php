<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImageUploadService
{
    /**
     * 画像をS3にアップロードする
     * 
     * @param UploadedFile $file アップロードするファイル
     * @param string $directory S3内のディレクトリ名
     * @param string|null $filename カスタムファイル名（nullの場合は自動生成）
     * @return array アップロード結果 ['success' => bool, 'url' => string|null, 'path' => string|null, 'error' => string|null]
     */
    public static function uploadImage(UploadedFile $file, string $directory, ?string $filename = null): array
    {
        try {
            // ファイル名を生成
            if (!$filename) {
                $filename = Str::uuid() . '_' . time() . '.' . $file->getClientOriginalExtension();
            }

            // S3のディスク設定を取得
            $diskName = self::getDiskName($directory);

            // S3にファイルを保存
            $path = $file->storeAs('', $filename, $diskName);

            // S3のURLを取得
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk($diskName);
            $imageUrl = $disk->url($path);

            // URLの正規化
            $normalizedUrl = str_replace('%5C', '/', $imageUrl);

            Log::info('Image uploaded successfully to S3', [
                'directory' => $directory,
                'filename' => $filename,
                'path' => $path,
                'url' => $normalizedUrl
            ]);

            return [
                'success' => true,
                'url' => $normalizedUrl,
                'path' => $path,
                'error' => null
            ];
        } catch (\Exception $e) {
            Log::error('Image upload failed', [
                'directory' => $directory,
                'filename' => $filename ?? 'auto-generated',
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'url' => null,
                'path' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 複数の画像をS3にアップロードする
     * 
     * @param array $files UploadedFileの配列
     * @param string $directory S3内のディレクトリ名
     * @param string|null $prefix ファイル名のプレフィックス
     * @return array アップロード結果の配列
     */
    public static function uploadMultipleImages(array $files, string $directory, ?string $prefix = null): array
    {
        $results = [];

        foreach ($files as $index => $file) {
            $filename = null;
            if ($prefix) {
                $filename = $prefix . '_' . ($index + 1) . '_' . time() . '.' . $file->getClientOriginalExtension();
            }

            $result = self::uploadImage($file, $directory, $filename);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * S3から画像を削除する
     * 
     * @param string $url 削除する画像のURL
     * @param string $directory S3内のディレクトリ名
     * @return bool 削除成功時はtrue
     */
    public static function deleteImage(string $url, string $directory): bool
    {
        try {
            // URLからパスを抽出
            $fullUrlPath = parse_url($url, PHP_URL_PATH);
            $fullPath = ltrim($fullUrlPath, '/');

            // ディレクトリ名に基づいてルートパスを削除
            $rootPath = self::getRootPath($directory);
            $relativePath = str_replace($rootPath . '/', '', $fullPath);

            // S3のディスク設定を取得
            $diskName = self::getDiskName($directory);

            // ファイルの存在を確認
            if (Storage::disk($diskName)->exists($relativePath)) {
                // ファイルを削除
                Storage::disk($diskName)->delete($relativePath);

                Log::info('Image deleted successfully from S3', [
                    'directory' => $directory,
                    'path' => $relativePath,
                    'url' => $url
                ]);

                return true;
            }

            Log::warning('Image not found for deletion', [
                'directory' => $directory,
                'path' => $relativePath,
                'url' => $url
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Image deletion failed', [
                'directory' => $directory,
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * ディレクトリ名に基づいてS3ディスク名を取得
     */
    private static function getDiskName(string $directory): string
    {
        return match ($directory) {
            'profile_images' => 'profile_images',
            'post_media' => 'post_media',
            default => 's3'
        };
    }

    /**
     * ディレクトリ名に基づいてS3のルートパスを取得
     */
    private static function getRootPath(string $directory): string
    {
        return match ($directory) {
            'profile_images' => 'profile-images',
            'post_media' => 'post-media',
            default => ''
        };
    }

    /**
     * 画像ファイルかどうかをチェック
     */
    public static function isValidImage(UploadedFile $file): bool
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
        return in_array($file->getMimeType(), $allowedMimes);
    }

    /**
     * 動画ファイルかどうかをチェック
     */
    public static function isValidVideo(UploadedFile $file): bool
    {
        $allowedMimes = ['video/mp4', 'video/mov', 'video/avi', 'video/quicktime'];
        return in_array($file->getMimeType(), $allowedMimes);
    }

    /**
     * ファイルサイズをチェック（MB単位）
     */
    public static function isValidFileSize(UploadedFile $file, int $maxSizeMB): bool
    {
        $maxSizeBytes = $maxSizeMB * 1024 * 1024;
        return $file->getSize() <= $maxSizeBytes;
    }
}
