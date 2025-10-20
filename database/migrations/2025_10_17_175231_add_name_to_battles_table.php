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
        Schema::table('battles', function (Blueprint $table) {
            if (!Schema::hasColumn('battles', 'name')) {
                $table->string('name')->nullable()->after('uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            if (Schema::hasColumn('battles', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};
