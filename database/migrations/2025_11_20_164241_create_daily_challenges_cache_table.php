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
        Schema::create('daily_challenges_cache', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('level_id');
            $table->unsignedBigInteger('grade_id');
            $table->json('questions'); // array of 5 question IDs
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('level_id')->references('id')->on('levels')->onDelete('cascade');
            $table->foreign('grade_id')->references('id')->on('grades')->onDelete('cascade');
            $table->unique(['date', 'level_id', 'grade_id']);
            $table->index(['date', 'level_id']);
            $table->index(['level_id', 'grade_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_challenges_cache');
    }
};
