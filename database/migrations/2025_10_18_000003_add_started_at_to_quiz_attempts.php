<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up()
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('quiz_attempts', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('per_question_time');
            }
        });
    }

    public function down()
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('quiz_attempts', 'started_at')) {
                $table->dropColumn('started_at');
            }
        });
    }
};
