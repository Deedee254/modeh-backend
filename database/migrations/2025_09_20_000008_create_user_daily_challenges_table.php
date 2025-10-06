<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_daily_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('daily_challenge_id');
            $table->timestamp('completed_at');
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('daily_challenge_id')->references('id')->on('daily_challenges')->onDelete('cascade');
            $table->unique(['user_id', 'daily_challenge_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_daily_challenges');
    }
};