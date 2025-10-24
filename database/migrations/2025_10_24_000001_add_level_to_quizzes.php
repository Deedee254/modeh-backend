<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('quizzes')) return;
        Schema::table('quizzes', function (Blueprint $table) {
            if (!Schema::hasColumn('quizzes', 'level_id')) {
                // nullable foreign key to levels; keep null on delete to preserve quizzes
                $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete()->after('grade_id');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('quizzes')) return;
        Schema::table('quizzes', function (Blueprint $table) {
            try { if (Schema::hasColumn('quizzes', 'level_id')) { $table->dropForeign(['level_id']); } } catch (\Throwable $e) {}
            if (Schema::hasColumn('quizzes', 'level_id')) { try { $table->dropColumn('level_id'); } catch (\Throwable $e) {} }
        });
    }
};
