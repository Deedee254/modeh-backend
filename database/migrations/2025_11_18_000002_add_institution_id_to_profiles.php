<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quiz_masters', function (Blueprint $table) {
            if (!Schema::hasColumn('quiz_masters', 'institution_id')) {
                $table->unsignedBigInteger('institution_id')->nullable()->after('level_id');
                $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('set null');
            }
        });

        Schema::table('quizees', function (Blueprint $table) {
            if (!Schema::hasColumn('quizees', 'institution_id')) {
                $table->unsignedBigInteger('institution_id')->nullable()->after('level_id');
                $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_masters', function (Blueprint $table) {
            if (Schema::hasColumn('quiz_masters', 'institution_id')) {
                $table->dropForeign(['institution_id']);
                $table->dropColumn('institution_id');
            }
        });

        Schema::table('quizees', function (Blueprint $table) {
            if (Schema::hasColumn('quizees', 'institution_id')) {
                $table->dropForeign(['institution_id']);
                $table->dropColumn('institution_id');
            }
        });
    }
};
