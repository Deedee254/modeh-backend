<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('battle_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('battle_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('question_id')->index();
            $table->json('selected')->nullable();
            $table->float('time_taken')->nullable();
            $table->boolean('correct_flag')->nullable();
            $table->timestamps();

            $table->unique(['battle_id', 'user_id', 'question_id'], 'battle_user_question_unique');
            $table->foreign('battle_id')->references('id')->on('battles')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('question_id')->references('id')->on('questions')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('battle_submissions');
    }
};
