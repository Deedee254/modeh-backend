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
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'correct')) {
                $table->dropColumn('correct');
            }
            if (Schema::hasColumn('questions', 'corrects')) {
                $table->dropColumn('corrects');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->integer('correct')->nullable()->after('answers');
            $table->json('corrects')->nullable()->after('correct');
        });
    }
};
