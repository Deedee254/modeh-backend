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
        Schema::create('daily_challenge_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('daily_challenge_cache_id');
            $table->json('answers'); // user's answers { "Q1_id": "answer", "Q2_id": "answer", ... }
            $table->integer('score'); // 0-100
            $table->json('is_correct'); // per-question correctness { "Q1_id": true, "Q2_id": false, ... }
            $table->integer('time_taken')->nullable(); // seconds
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('daily_challenge_cache_id')->references('id')->on('daily_challenges_cache')->onDelete('cascade');
            $table->unique(['user_id', 'daily_challenge_cache_id'], 'user_cache_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_challenge_submissions');
    }
};
