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
     * Backfill missing grade_id and level_id for questions that have other taxonomy fields set.
     * Uses three strategies to populate missing IDs:
     * 1. From the question's quiz relationship
     * 2. From the question's subject relationship  
     * 3. From the question's topic relationship (via subject)
     */
    public function up(): void
    {
        if (!Schema::hasTable('questions')) {
            return;
        }

        // Use a transaction and UPDATE ... JOIN statements to safely fill missing IDs.
        // Each statement only updates rows where the target column is NULL so it is safe
        // to re-run multiple times (idempotent).
        DB::transaction(function () {
            // Strategy 1: Fill grade_id from quiz for questions that have a quiz_id but no grade_id
            DB::statement("
                UPDATE questions q
                INNER JOIN quizzes ON q.quiz_id = quizzes.id
                SET q.grade_id = quizzes.grade_id
                WHERE q.quiz_id IS NOT NULL
                AND q.grade_id IS NULL
                AND quizzes.grade_id IS NOT NULL
            ");

            // Strategy 2: Fill grade_id from subject for questions that have a subject_id but no grade_id
            DB::statement("
                UPDATE questions q
                INNER JOIN subjects ON q.subject_id = subjects.id
                SET q.grade_id = subjects.grade_id
                WHERE q.subject_id IS NOT NULL
                AND q.grade_id IS NULL
                AND subjects.grade_id IS NOT NULL
            ");

            // Strategy 3: Fill grade_id from topic's subject for questions that have a topic_id but no grade_id
            DB::statement("
                UPDATE questions q
                INNER JOIN topics ON q.topic_id = topics.id
                INNER JOIN subjects ON topics.subject_id = subjects.id
                SET q.grade_id = subjects.grade_id
                WHERE q.topic_id IS NOT NULL
                AND q.grade_id IS NULL
                AND subjects.grade_id IS NOT NULL
            ");

            // Strategy 4: Fill level_id from grade for questions that have a grade_id but no level_id
            DB::statement("
                UPDATE questions q
                INNER JOIN grades ON q.grade_id = grades.id
                SET q.level_id = grades.level_id
                WHERE q.grade_id IS NOT NULL
                AND q.level_id IS NULL
                AND grades.level_id IS NOT NULL
            ");
        });

        // Log summary of changes
        $updatedCount = DB::table('questions')
            ->whereNotNull('grade_id')
            ->count();
        
        \Log::info('Backfill question grade_id and level_id migration completed', [
            'total_questions_with_grade_id' => $updatedCount,
            'timestamp' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration fills in missing data, not structural changes
        // Rolling back would lose data, so we don't provide a down() method
        // If needed, restore from backup or manually revert specific records
    }
};
