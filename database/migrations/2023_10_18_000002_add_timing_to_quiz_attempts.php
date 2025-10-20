<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('quiz_attempts', 'total_time_seconds')) {
                $table->integer('total_time_seconds')->nullable()->after('points_earned');
            }
            if (!Schema::hasColumn('quiz_attempts', 'per_question_time')) {
                $table->json('per_question_time')->nullable()->after('total_time_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('quiz_attempts', 'per_question_time')) {
                $table->dropColumn('per_question_time');
            }
            if (Schema::hasColumn('quiz_attempts', 'total_time_seconds')) {
                $table->dropColumn('total_time_seconds');
            }
        });
    }
};
