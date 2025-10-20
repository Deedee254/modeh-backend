<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('quiz_likes', function (Blueprint $table) {
            $table->unsignedBigInteger('quiz_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->primary(['quiz_id', 'user_id']);
            $table->foreign('quiz_id')->references('id')->on('quizzes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Ensure quizzes table has a cached likes_count column
        if (Schema::hasTable('quizzes') && ! Schema::hasColumn('quizzes', 'likes_count')) {
            Schema::table('quizzes', function (Blueprint $table) {
                $table->unsignedInteger('likes_count')->default(0);
            });
        }
    }

    public function down()
    {
        // Remove likes_count from quizzes if it exists
        if (Schema::hasTable('quizzes') && Schema::hasColumn('quizzes', 'likes_count')) {
            Schema::table('quizzes', function (Blueprint $table) {
                $table->dropColumn('likes_count');
            });
        }

        Schema::dropIfExists('quiz_likes');
    }
};
