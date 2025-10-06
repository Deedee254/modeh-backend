<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('daily_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->enum('difficulty', ['easy', 'medium', 'hard']);
            $table->integer('points_reward');
            $table->date('challenge_date')->unique();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->json('quiz_ids')->nullable(); // array of quiz IDs for this challenge
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('set null');
            $table->foreign('grade_id')->references('id')->on('grades')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('daily_challenges');
    }
};