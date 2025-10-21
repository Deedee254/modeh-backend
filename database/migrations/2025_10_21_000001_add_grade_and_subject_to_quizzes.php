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
            if (!Schema::hasColumn('quizzes', 'subject_id')) {
                // add subject_id if missing (some deployments may already have it elsewhere)
                $table->unsignedBigInteger('subject_id')->nullable()->after('topic_id');
                $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('set null');
            }
            if (!Schema::hasColumn('quizzes', 'grade_id')) {
                $table->unsignedBigInteger('grade_id')->nullable()->after('subject_id');
                $table->foreign('grade_id')->references('id')->on('grades')->onDelete('set null');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('quizzes')) return;
        Schema::table('quizzes', function (Blueprint $table) {
            try { if (Schema::hasColumn('quizzes', 'grade_id')) { $table->dropForeign(['grade_id']); } } catch (\Throwable $e) {}
            try { if (Schema::hasColumn('quizzes', 'subject_id')) { $table->dropForeign(['subject_id']); } } catch (\Throwable $e) {}
            if (Schema::hasColumn('quizzes', 'grade_id')) { try { $table->dropColumn('grade_id'); } catch (\Throwable $e) {} }
            if (Schema::hasColumn('quizzes', 'subject_id')) { try { $table->dropColumn('subject_id'); } catch (\Throwable $e) {} }
        });
    }
};
