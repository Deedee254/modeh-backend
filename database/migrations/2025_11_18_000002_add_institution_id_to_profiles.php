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
                // Only use the AFTER modifier when the referenced column exists.
                if (Schema::hasColumn('quiz_masters', 'level_id')) {
                    $table->unsignedBigInteger('institution_id')->nullable()->after('level_id');
                } else {
                    // Add without positional modifier to avoid SQL errors when level_id is missing.
                    $table->unsignedBigInteger('institution_id')->nullable();
                }

                // Add foreign key constraint if possible.
                $table->foreign('institution_id')->references('id')->on('institutions')->onDelete('set null');
            }
        });

        Schema::table('quizees', function (Blueprint $table) {
            if (!Schema::hasColumn('quizees', 'institution_id')) {
                if (Schema::hasColumn('quizees', 'level_id')) {
                    $table->unsignedBigInteger('institution_id')->nullable()->after('level_id');
                } else {
                    $table->unsignedBigInteger('institution_id')->nullable();
                }

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
