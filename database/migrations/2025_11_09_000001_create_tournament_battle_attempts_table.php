<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tournament_battle_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_id')->constrained('tournament_battles')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->text('answer')->nullable();
            $table->decimal('points', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['battle_id', 'player_id', 'question_id'], 'tba_unique_battle_player_question');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tournament_battle_attempts');
    }
};
