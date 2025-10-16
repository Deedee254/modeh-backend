<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('quizzes')) {
            Schema::table('quizzes', function (Blueprint $table) {
                if (!Schema::hasColumn('quizzes', 'one_off_price')) {
                    $table->decimal('one_off_price', 10, 2)->nullable()->after('is_paid');
                }
            });
        }
        if (Schema::hasTable('battles')) {
            Schema::table('battles', function (Blueprint $table) {
                if (!Schema::hasColumn('battles', 'one_off_price')) {
                    $table->decimal('one_off_price', 10, 2)->nullable();
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('quizzes')) {
            Schema::table('quizzes', function (Blueprint $table) {
                if (Schema::hasColumn('quizzes', 'one_off_price')) $table->dropColumn('one_off_price');
            });
        }
        if (Schema::hasTable('battles')) {
            Schema::table('battles', function (Blueprint $table) {
                if (Schema::hasColumn('battles', 'one_off_price')) $table->dropColumn('one_off_price');
            });
        }
    }
};
