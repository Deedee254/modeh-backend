<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tournament_battle_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_battle_id')->constrained('tournament_battles')->onDelete('cascade');
            $table->foreignId('question_id')->constrained('questions')->onDelete('cascade');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['tournament_battle_id', 'question_id'], 'tbq_battle_question_uq');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tournament_battle_questions');
    }
};
