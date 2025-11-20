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
        // Add indexes for daily_challenge_submissions performance
        Schema::table('daily_challenge_submissions', function (Blueprint $table) {
            $table->index(['user_id', 'completed_at']);
            $table->index(['daily_challenge_cache_id', 'score']);
        });

        // Note: Indexes for daily_challenges_cache are already defined in the create table migration:
        // - index(['date', 'level_id'])
        // - index(['level_id', 'grade_id'])
        // So we don't add them again here to avoid duplicate key errors.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_challenge_submissions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'completed_at']);
            $table->dropIndex(['daily_challenge_cache_id', 'score']);
        });

        // Indexes for daily_challenges_cache are handled by the create table migration down().
    }
};
