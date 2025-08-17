<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdatePostViewsCountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 既存の投稿のビュー数をPostViewテーブルから集計して更新
        $posts = DB::table('posts')->get();

        foreach ($posts as $post) {
            $viewCount = DB::table('post_views')
                ->where('post_id', $post->id)
                ->count();

            DB::table('posts')
                ->where('id', $post->id)
                ->update(['views_count' => $viewCount]);
        }

        $this->command->info('既存投稿のビュー数を更新しました。');
    }
}
