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
        Schema::create('reply_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->uuid('reply_id');
            $table->enum('action_type', ['like', 'spark']);
            $table->timestamps();

            // 外部キー制約
            $table->foreign('reply_id')->references('id')->on('replies')->onDelete('cascade');

            // 同じユーザーが同じリプライに同じアクションを複数回できないようにする
            $table->unique(['user_id', 'reply_id', 'action_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reply_actions');
    }
};
