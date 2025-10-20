<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->integer('per_question_seconds')->nullable()->after('timer_seconds');
            $table->boolean('use_per_question_timer')->default(false)->after('per_question_seconds');
        });
    }

    public function down()
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['per_question_seconds', 'use_per_question_timer']);
        });
    }
};
