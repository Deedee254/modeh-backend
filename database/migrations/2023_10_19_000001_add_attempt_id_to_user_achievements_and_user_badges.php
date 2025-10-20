<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_achievements', function (Blueprint $table) {
            if (!Schema::hasColumn('user_achievements', 'attempt_id')) {
                $table->unsignedBigInteger('attempt_id')->nullable()->after('achievement_id');
                $table->foreign('attempt_id')->references('id')->on('quiz_attempts')->nullOnDelete();
            }
        });

        Schema::table('user_badges', function (Blueprint $table) {
            if (!Schema::hasColumn('user_badges', 'attempt_id')) {
                $table->unsignedBigInteger('attempt_id')->nullable()->after('badge_id');
                $table->foreign('attempt_id')->references('id')->on('quiz_attempts')->nullOnDelete();
            }
        });
    }

    public function down()
    {
        Schema::table('user_achievements', function (Blueprint $table) {
            if (Schema::hasColumn('user_achievements', 'attempt_id')) {
                $table->dropForeign(['attempt_id']);
                $table->dropColumn('attempt_id');
            }
        });

        Schema::table('user_badges', function (Blueprint $table) {
            if (Schema::hasColumn('user_badges', 'attempt_id')) {
                $table->dropForeign(['attempt_id']);
                $table->dropColumn('attempt_id');
            }
        });
    }
};
