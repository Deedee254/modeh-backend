<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('questions', 'level_id')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->foreignId('level_id')->nullable()->after('grade_id')->constrained('levels')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('questions', 'level_id')) {
            Schema::table('questions', function (Blueprint $table) {
                try { $table->dropConstrainedForeignId('level_id'); } catch (\Throwable $_) { try { $table->dropColumn('level_id'); } catch (\Throwable $__ ) {} }
            });
        }
    }
};
