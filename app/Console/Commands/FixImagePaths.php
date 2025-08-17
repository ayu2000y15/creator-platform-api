<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Media;

class FixImagePaths extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:image-paths';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix image paths that were incorrectly stored as JSON';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fixing profile image paths...');

        // Fix profile images
        $users = User::whereNotNull('profile_image')->get();
        foreach ($users as $user) {
            $profileImage = $user->profile_image;

            // Check if it's JSON encoded
            if ($this->isJson($profileImage)) {
                $decoded = json_decode($profileImage, true);
                if (isset($decoded['url'])) {
                    $user->update(['profile_image' => $decoded['url']]);
                    $this->info("Fixed profile image for user {$user->id}");
                }
            }
        }

        $this->info('Fixing media file paths...');

        // Fix media file paths
        $mediaFiles = Media::all();
        foreach ($mediaFiles as $media) {
            $filePath = $media->file_path;

            // Check if it's JSON encoded
            if ($this->isJson($filePath)) {
                $decoded = json_decode($filePath, true);
                if (isset($decoded['url'])) {
                    $media->update(['file_path' => $decoded['url']]);
                    $this->info("Fixed media file {$media->id}");
                }
            }
        }

        $this->info('Image path fixing completed!');
        return Command::SUCCESS;
    }

    private function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
