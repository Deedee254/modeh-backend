<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('questions')) {
            \Log::info('Skipping add_parts_and_solution_steps_to_questions migration: questions table not present yet.');
            return;
        }

        Schema::table('questions', function (Blueprint $table) {
            if (!Schema::hasColumn('questions', 'parts')) {
                $table->json('parts')->nullable()->after('answers');
            }
            if (!Schema::hasColumn('questions', 'solution_steps')) {
                $table->json('solution_steps')->nullable()->after('parts');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('questions')) return;

        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'solution_steps')) {
                try { $table->dropColumn('solution_steps'); } catch (\Throwable $e) {}
            }
            if (Schema::hasColumn('questions', 'parts')) {
                try { $table->dropColumn('parts'); } catch (\Throwable $e) {}
            }
        });
    }
};
