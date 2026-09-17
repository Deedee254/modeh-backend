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
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('media_type')->default('image'); // 'image' or 'video'
            $table->text('media_url');
            $table->text('destination_url')->nullable();
            $table->string('cta_text')->default('Learn More');
            $table->unsignedInteger('duration_seconds')->default(10);
            $table->unsignedInteger('skip_after_seconds')->nullable();
            $table->string('target_type')->default('all'); // 'all', 'quiz', 'taxonomy'
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active'); // 'draft', 'pending_approval', 'active', 'paused', 'completed'
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'status']);
            $table->index('target_type');
        });

        Schema::create('ad_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
            $table->string('target_type'); // 'quiz', 'subject', 'topic', 'grade', 'level'
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['ad_id', 'target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_targets');
        Schema::dropIfExists('ads');
    }
};
