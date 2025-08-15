<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_image')->nullable(); // プロフィール画像URL
            $table->string('username')->nullable()->unique(); // ユーザー名（@username形式）
            $table->text('bio')->nullable(); // 自己紹介文（300字程度）
            $table->enum('birthday_visibility', ['full', 'month_day', 'hidden'])->default('hidden'); // 生年月日の表示設定
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'profile_image',
                'username',
                'bio',
                'birthday_visibility'
            ]);
        });
    }
};
