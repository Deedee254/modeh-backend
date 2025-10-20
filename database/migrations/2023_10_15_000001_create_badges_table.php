<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('icon')->nullable(); // emoji or icon class
            $table->string('criteria_type'); // 'quiz_score', 'battle_wins', 'streak', etc.
            $table->json('criteria_conditions'); // {"min_score": 70, "quiz_count": 10, "difficulty": "hard"}
            $table->integer('points_reward')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('badges');
    }
};