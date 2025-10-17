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
            // Only add the subjects column as grade_id and institution were added in previous migration
            if (!Schema::hasColumn('quiz_masters', 'subjects')) {
                $table->json('subjects')->nullable()->after('institution');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_masters', function (Blueprint $table) {
            if (Schema::hasColumn('quiz_masters', 'subjects')) {
                $table->dropColumn('subjects');
            }
        });
    }
};
