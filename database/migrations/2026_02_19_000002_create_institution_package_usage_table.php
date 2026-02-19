<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create a new table to track institution package usage
     * Used to enforce seat limits and quiz attempt limits per institution
     */
    public function up(): void
    {
        if (!Schema::hasTable('institution_package_usage')) {
            Schema::create('institution_package_usage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('institution_id');
                $table->unsignedBigInteger('subscription_id')->nullable(); // The active subscription if from institution subscription
                $table->unsignedBigInteger('user_id'); // The member making the attempt
                $table->enum('usage_type', ['seat', 'quiz_attempt']); // Type of usage being tracked
                $table->integer('count')->default(1); // Can batch record multiple attempts
                $table->date('usage_date')->index(); // Track daily usage for quota reset
                $table->json('metadata')->nullable(); // Additional context (quiz_id, etc)
                $table->timestamps();

                $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('cascade');
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['institution_id', 'usage_date']);
                $table->index(['subscription_id', 'usage_date']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_package_usage');
    }
};
