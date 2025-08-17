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
        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('view_permission', ['public', 'followers', 'mutuals'])->default('public');
            $table->enum('comment_permission', ['public', 'followers', 'mutuals'])->default('public');
            $table->boolean('is_sensitive')->default(false);
            $table->enum('content_type', ['video', 'short_video', 'text', 'quote']);
            $table->string('text_content', 140)->nullable();
            $table->uuid('quoted_post_id')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->integer('price')->nullable();
            $table->text('introduction')->nullable();
            $table->timestamps();

            $table->foreign('quoted_post_id')->references('id')->on('posts')->onDelete('set null');
            $table->index(['user_id', 'created_at']);
            $table->index(['content_type', 'created_at']);
            $table->index('is_sensitive');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
