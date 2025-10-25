<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add indexes to created_at columns used for grouping/filtering in dashboards.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at', 'users_created_at_index');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->index('created_at', 'quiz_attempts_created_at_index');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->index('created_at', 'quizzes_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_created_at_index');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex('quiz_attempts_created_at_index');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex('quizzes_created_at_index');
        });
    }
};
