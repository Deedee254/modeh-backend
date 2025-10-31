<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quizees', function (Blueprint $table) {
            // Add nullable level_id and constrain to levels.id
            $table->foreignId('level_id')->nullable()->after('grade_id')->constrained('levels')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('quizees', function (Blueprint $table) {
            $table->dropForeign(['level_id']);
            $table->dropColumn('level_id');
        });
    }
};
