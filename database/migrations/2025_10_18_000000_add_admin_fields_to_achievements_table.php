<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->string('slug')->unique()->after('name');
            $table->string('color')->nullable()->after('icon');
            $table->boolean('is_active')->default(true)->after('criteria_value');
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn(['slug', 'color', 'is_active']);
        });
    }
};