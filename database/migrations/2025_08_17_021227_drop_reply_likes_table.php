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
        Schema::dropIfExists('reply_likes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('reply_likes', function (Blueprint $table) {
            $table->id();
            $table->uuid('reply_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // 外部キー制約
            $table->foreign('reply_id')->references('id')->on('replies')->onDelete('cascade');

            // 同じユーザーが同じリプライに複数回いいねできないようにする
            $table->unique(['reply_id', 'user_id']);
        });
    }
};
