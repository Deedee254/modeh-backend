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
        Schema::table('tournaments', function (Blueprint $table) {
            $table->renameColumn('qualifier_question_count', 'question_count');
            $table->renameColumn('qualifier_per_question_seconds', 'per_question_seconds');
            $table->renameColumn('qualifier_tie_breaker', 'tie_breaker');
            $table->renameColumn('qualifier_days', 'duration_days');
        });

        Schema::rename('tournament_qualification_attempts', 'tournament_attempts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('tournament_attempts', 'tournament_qualification_attempts');

        Schema::table('tournaments', function (Blueprint $table) {
            $table->renameColumn('question_count', 'qualifier_question_count');
            $table->renameColumn('per_question_seconds', 'qualifier_per_question_seconds');
            $table->renameColumn('tie_breaker', 'qualifier_tie_breaker');
            $table->renameColumn('duration_days', 'qualifier_days');
        });
    }
};
