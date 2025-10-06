<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('questions', function (Blueprint $table) {
            // additional metadata referenced across frontend and model
            if (!Schema::hasColumn('questions', 'tags')) {
                $table->json('tags')->nullable()->after('is_banked');
            }

            if (!Schema::hasColumn('questions', 'hint')) {
                $table->text('hint')->nullable()->after('tags');
            }

            if (!Schema::hasColumn('questions', 'solution_steps')) {
                $table->json('solution_steps')->nullable()->after('hint');
            }

            // link to curriculum entities used elsewhere in the app
            if (!Schema::hasColumn('questions', 'subject_id')) {
                $table->unsignedBigInteger('subject_id')->nullable()->after('quiz_id');
                $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('set null');
            }

            if (!Schema::hasColumn('questions', 'topic_id')) {
                $table->unsignedBigInteger('topic_id')->nullable()->after('subject_id');
                $table->foreign('topic_id')->references('id')->on('topics')->onDelete('set null');
            }

            if (!Schema::hasColumn('questions', 'grade_id')) {
                $table->unsignedBigInteger('grade_id')->nullable()->after('topic_id');
                $table->foreign('grade_id')->references('id')->on('grades')->onDelete('set null');
            }

            // additional metadata only; `for_battle` flag deprecated and removed.
        });
    }

    public function down()
    {
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'for_battle')) {
                $table->dropColumn('for_battle');
            }

            if (Schema::hasColumn('questions', 'grade_id')) {
                $table->dropForeign(['grade_id']);
                $table->dropColumn('grade_id');
            }

            if (Schema::hasColumn('questions', 'topic_id')) {
                $table->dropForeign(['topic_id']);
                $table->dropColumn('topic_id');
            }

            if (Schema::hasColumn('questions', 'subject_id')) {
                $table->dropForeign(['subject_id']);
                $table->dropColumn('subject_id');
            }

            if (Schema::hasColumn('questions', 'solution_steps')) {
                $table->dropColumn('solution_steps');
            }

            if (Schema::hasColumn('questions', 'hint')) {
                $table->dropColumn('hint');
            }

            if (Schema::hasColumn('questions', 'tags')) {
                $table->dropColumn('tags');
            }
        });
    }
};
