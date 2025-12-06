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
        Schema::create('tournament_qualification_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tournament_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->decimal('score', 8, 2)->default(0);
            $table->json('answers')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            // Foreign keys are optional for now to avoid migration ordering problems in some environments
            // $table->foreign('tournament_id')->references('id')->on('tournaments')->onDelete('cascade');
            // $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_qualification_attempts');
    }
};
