<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add `reject_reason` (text nullable) and `rejected_at` (timestamp nullable)
     * to quizzes, subjects, topics and questions so we can persist rejection metadata.
     */
    public function up(): void
    {
        $tables = ['quizzes', 'subjects', 'topics', 'questions'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($table) {
                    if (!Schema::hasColumn($table, 'reject_reason')) {
                        $tableBlueprint->text('reject_reason')->nullable()->after('approval_requested_at')->comment('Optional admin-provided rejection reason');
                    }
                    if (!Schema::hasColumn($table, 'rejected_at')) {
                        $tableBlueprint->timestamp('rejected_at')->nullable()->after('reject_reason')->comment('When the resource was rejected');
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['quizzes', 'subjects', 'topics', 'questions'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($table) {
                    if (Schema::hasColumn($table, 'reject_reason')) {
                        $tableBlueprint->dropColumn('reject_reason');
                    }
                    if (Schema::hasColumn($table, 'rejected_at')) {
                        $tableBlueprint->dropColumn('rejected_at');
                    }
                });
            }
        }
    }
};
