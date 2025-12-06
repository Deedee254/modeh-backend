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
            // Qualifier configuration
            $table->integer('qualifier_per_question_seconds')->default(30)->after('max_participants');
            $table->integer('qualifier_question_count')->default(10)->after('qualifier_per_question_seconds');
            
            // Battle configuration
            $table->integer('battle_per_question_seconds')->default(30)->after('qualifier_question_count');
            $table->integer('battle_question_count')->default(10)->after('battle_per_question_seconds');
            
            // Tie-breaker and selection rules
            $table->string('qualifier_tie_breaker')->default('duration')->comment('duration|score_then_duration')->after('battle_question_count');
            $table->integer('bracket_slots')->default(8)->comment('8, 4, or 2')->after('qualifier_tie_breaker');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn([
                'qualifier_per_question_seconds',
                'qualifier_question_count',
                'battle_per_question_seconds',
                'battle_question_count',
                'qualifier_tie_breaker',
                'bracket_slots',
            ]);
        });
    }
};
