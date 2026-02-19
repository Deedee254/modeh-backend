<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Changes business model from subscriptions to pay-per-result + institutional packages:
     * 1. Add institution_id to quizzes table (nullable - can be public or institutional)
     * 2. Drop subscription tracking columns from quiz_attempts (no longer needed)
     * 3. Add access type to track whether quiz is free/paid and institutional/public
     */
    public function up(): void
    {
        // Add institutional fields to quizzes table
        Schema::table('quizzes', function (Blueprint $table) {
            if (!Schema::hasColumn('quizzes', 'institution_id')) {
                $table->unsignedBigInteger('institution_id')->nullable()->after('user_id');
                $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('set null');
            }

            if (!Schema::hasColumn('quizzes', 'is_institutional')) {
                $table->boolean('is_institutional')->default(false)->after('institution_id')->index();
            }
        });

        // Remove subscription tracking from quiz_attempts (no longer used in new model)
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Drop subscription-related columns if they exist
            if (Schema::hasColumn('quiz_attempts', 'subscription_id')) {
                $table->dropForeign(['subscription_id']);
                $table->dropColumn('subscription_id');
            }
            
            if (Schema::hasColumn('quiz_attempts', 'subscription_type')) {
                $table->dropColumn('subscription_type');
            }

            // Add columns to track payment/access for this attempt
            if (!Schema::hasColumn('quiz_attempts', 'paid_for')) {
                // Whether this attempt was paid for (one-off payment or free institutional access)
                $table->boolean('paid_for')->default(false)->after('quiz_id');
            }

            if (!Schema::hasColumn('quiz_attempts', 'institution_access')) {
                // If paid_for=false, was this free institutional access? (or free public quiz)
                $table->boolean('institution_access')->default(false)->after('paid_for');
            }

            if (!Schema::hasColumn('quiz_attempts', 'institution_id')) {
                // Which institution (if any) was used for free access
                $table->unsignedBigInteger('institution_id')->nullable()->after('institution_access');
                $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('quiz_attempts', 'institution_id')) {
                $table->dropForeign(['institution_id']);
                $table->dropColumn('institution_id');
            }
            
            if (Schema::hasColumn('quiz_attempts', 'institution_access')) {
                $table->dropColumn('institution_access');
            }

            if (Schema::hasColumn('quiz_attempts', 'paid_for')) {
                $table->dropColumn('paid_for');
            }

            // Restore subscription columns if needed for rollback
            $table->unsignedBigInteger('subscription_id')->nullable()->after('quiz_id');
            $table->enum('subscription_type', ['personal', 'institution', 'one_off'])->nullable()->after('subscription_id');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            if (Schema::hasColumn('quizzes', 'is_institutional')) {
                $table->dropColumn('is_institutional');
            }

            if (Schema::hasColumn('quizzes', 'institution_id')) {
                $table->dropForeign(['institution_id']);
                $table->dropColumn('institution_id');
            }
        });
    }
};
